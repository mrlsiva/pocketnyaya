<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Development only: a key file outside the web root. Losing it loses every
 * document encrypted under it, so production must use a KMS with escrow (C7, C31).
 */
class LocalKeyProvider implements KeyProvider
{
    private const CIPHER = 'aes-256-gcm';

    public function activeVersionId(string $purpose = 'documents'): int
    {
        $id = DB::table('encryption_key_versions')->where(['purpose' => $purpose, 'status' => 'active'])->value('id');
        if ($id === null && $purpose === 'journal') {
            // Development only: consent can't wait for someone to run a command.
            return $this->createVersion('journal');
        }
        if ($id === null) {
            throw new RuntimeException('No active document key. Run: php artisan pocketnyaya:document-key');
        }

        return (int) $id;
    }

    public function wrap(string $dek, int $versionId, string $context): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($dek, self::CIPHER, $this->key($versionId), OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Could not wrap the data key.');
        }

        return base64_encode($nonce.$tag.$ciphertext);
    }

    public function unwrap(string $wrapped, int $versionId, string $context): string
    {
        $raw = (string) base64_decode($wrapped, true);
        $dek = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key($versionId), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $context);
        if ($dek === false) {
            throw new RuntimeException('Could not unwrap the data key.');
        }

        return $dek;
    }

    /** Creates a new active key version, and moves the previous one to decrypt-only. */
    public function createVersion(string $purpose = 'documents'): int
    {
        $key = random_bytes(32);
        $id = (int) DB::table('encryption_key_versions')->max('id') + 1;
        $directory = self::directory();

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the key folder.');
        }
        if (file_put_contents(self::path($id), base64_encode($key), LOCK_EX) === false) {
            throw new RuntimeException('Could not write the key file.');
        }

        DB::transaction(function () use ($id, $key, $purpose) {
            DB::table('encryption_key_versions')->where(['purpose' => $purpose, 'status' => 'active'])
                ->update(['status' => 'decrypt_only', 'retired_at' => now(), 'updated_at' => now()]);
            DB::table('encryption_key_versions')->insert([
                'id' => $id, 'purpose' => $purpose, 'provider' => 'local', 'key_ref' => "local:{$purpose}-kek-{$id}",
                'kcv' => substr(hash_hmac('sha256', 'pocketnyaya-kcv', $key), 0, 16),
                'status' => 'active', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $id;
    }

    public static function directory(): string
    {
        return (string) config('pocketnyaya.document_key_dir');
    }

    private static function path(int $versionId): string
    {
        return self::directory().DIRECTORY_SEPARATOR."document-kek-{$versionId}.key";
    }

    private function key(int $versionId): string
    {
        $encoded = @file_get_contents(self::path($versionId));
        if ($encoded === false) {
            throw new RuntimeException("Document key version {$versionId} is not available.");
        }

        $key = (string) base64_decode($encoded, true);
        $kcv = DB::table('encryption_key_versions')->where('id', $versionId)->value('kcv');
        if (! hash_equals((string) $kcv, substr(hash_hmac('sha256', 'pocketnyaya-kcv', $key), 0, 16))) {
            throw new RuntimeException("Document key version {$versionId} failed its check value.");
        }

        return $key;
    }
}
