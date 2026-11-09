<?php

namespace App\Services;

use App\Models\ConsentPurpose;
use App\Models\User;
use App\Services\Documents\KeyProvider;
use App\Support\ConsentHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The tamper-evident consent ledger (C29, C36, C44, C48, MY-10). A locked user row
 * serialises each user's chain. On the approved MySQL target this logic moves
 * into the DEFINER procedure (MY-8), which needs separate database accounts.
 */
class ConsentLedger
{
    public function __construct(private KeyProvider $keys) {}

    public function grant(User $user, ConsentPurpose $purpose, string $channel = 'web'): void
    {
        $this->append($user, $purpose, 'granted', $channel);
    }

    public function withdraw(User $user, ConsentPurpose $purpose, string $channel = 'web'): void
    {
        if ($purpose->is_mandatory) {
            throw ValidationException::withMessages(['purpose' => 'This purpose is needed to run your account. To stop it, ask for your account to be erased.']);
        }
        $this->append($user, $purpose, 'withdrawn', $channel);
    }

    public function hasConsent(User $user, string $code): bool
    {
        return DB::table('user_consents as c')->join('consent_purposes as p', 'p.id', '=', 'c.consent_purpose_id')
            ->where('c.user_id', $user->id)->where('p.code', $code)->where('c.status', 'granted')->exists();
    }

    /** Recomputes the chain. Returns the first broken sequence number, or null if intact. */
    public function firstBrokenLink(User $user): ?int
    {
        return $this->firstBrokenIn(DB::table('consent_records')->where('user_id', $user->id)->orderBy('sequence_no')->get()->all());
    }

    /** @param list<object> $rows In sequence order. */
    public function firstBrokenIn(array $rows): ?int
    {
        $previous = null;
        foreach ($rows as $row) {
            $expected = ConsentHash::v3([
                'user_id' => $row->user_id, 'sequence_no' => $row->sequence_no, 'consent_purpose_id' => $row->consent_purpose_id,
                'action' => $row->action, 'channel' => $row->channel, 'created_at' => $this->stamp($row->created_at),
                'consent_key_id' => $row->consent_key_id, 'evidence_commitment' => $row->evidence_commitment, 'prev_hash' => $row->prev_hash,
            ]);
            if ($row->prev_hash !== $previous || ! hash_equals($expected, $row->record_hash)) {
                return (int) $row->sequence_no;
            }
            $previous = $row->record_hash;
        }

        return null;
    }

    /**
     * The owner's own evidence. Nobody else can call this (D8.12).
     *
     * @return array<string, mixed>|null Null once the consent key has been destroyed.
     */
    public function evidence(User $user, object $record): ?array
    {
        if ($user->id !== $record->user_id || $user->consent_key_wrapped === null || $user->consent_key_id !== $record->consent_key_id) {
            return null;
        }

        $key = $this->unwrap($user);
        $raw = (string) base64_decode($record->evidence_encrypted, true);
        $aad = $record->id.$record->user_id.$record->consent_key_id.'consent-evidence';
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        sodium_memzero($key);

        return $plain === false ? null : (array) json_decode($plain, true);
    }

    private function append(User $user, ConsentPurpose $purpose, string $action, string $channel): void
    {
        DB::transaction(function () use ($user, $purpose, $action, $channel) {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->consent_key_destroyed_at !== null) {
                throw new RuntimeException('This account has been erased.');
            }
            $key = $this->keyFor($locked);

            $last = DB::table('consent_records')->where('user_id', $locked->id)->orderByDesc('sequence_no')->first(['sequence_no', 'record_hash']);
            $sequence = $last === null ? 1 : ((int) $last->sequence_no) + 1;
            $id = (string) Str::uuid7();
            $createdAt = now()->utc();
            $request = request();

            $evidence = $this->canonical([
                'ip' => $request->ip(),
                'purpose_code' => $purpose->code,
                'purpose_version' => $purpose->version,
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);
            $commitment = hash_hmac('sha256', $evidence, hash_hkdf('sha256', $key, 32, 'consent-commitment'));

            $nonce = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($evidence, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $id.$locked->id.$locked->consent_key_id.'consent-evidence', 16);
            sodium_memzero($key);
            if ($ciphertext === false) {
                throw new RuntimeException('Could not encrypt the consent evidence.');
            }

            $fields = [
                'user_id' => $locked->id, 'sequence_no' => $sequence, 'consent_purpose_id' => $purpose->id,
                'action' => $action, 'channel' => $channel, 'created_at' => $createdAt->format('Y-m-d\TH:i:s.u\Z'),
                'consent_key_id' => (string) $locked->consent_key_id, 'evidence_commitment' => $commitment, 'prev_hash' => $last?->record_hash,
            ];

            DB::table('consent_records')->insert([
                'id' => $id,
                'evidence_encrypted' => base64_encode($nonce.$tag.$ciphertext),
                'record_hash' => ConsentHash::v3($fields),
            ] + array_merge($fields, ['created_at' => $createdAt->format('Y-m-d H:i:s.u')]));

            DB::table('user_consents')->updateOrInsert(
                ['user_id' => $locked->id, 'consent_purpose_id' => $purpose->id],
                ['status' => $action === 'withdrawn' ? 'withdrawn' : 'granted', 'updated_at' => $createdAt->format('Y-m-d H:i:s.u')],
            );
            $user->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Creates the user's consent key on first use. Runs under the user row lock. */
    private function keyFor(User $user): string
    {
        if ($user->consent_key_wrapped !== null) {
            return $this->unwrap($user);
        }

        $key = random_bytes(32);
        $keyId = (string) Str::uuid7();
        $version = $this->keys->activeVersionId('journal');
        $user->forceFill([
            'consent_key_id' => $keyId,
            'consent_key_wrapped' => $this->keys->wrap($key, $version, $this->context($keyId)),
            'consent_key_kek_version' => $version,
        ])->save();

        return $key;
    }

    private function unwrap(User $user): string
    {
        return $this->keys->unwrap((string) $user->consent_key_wrapped, (int) $user->consent_key_kek_version, $this->context((string) $user->consent_key_id));
    }

    private function context(string $keyId): string
    {
        return 'pocketnyaya/consent-key/'.$keyId;
    }

    /**
     * C48: sorted keys, no escaped slashes or Unicode, so the same evidence always hashes alike.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function canonical(array $evidence): string
    {
        ksort($evidence);

        return (string) json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function stamp(string $value): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
