<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentKey;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores and returns document files. Files are encrypted before they touch the
 * disk, and plaintext data keys live in memory for one call only (C31).
 */
class DocumentVault
{
    public const DISK = 'documents';

    public function __construct(private KeyProvider $keys, private ChunkedCipher $cipher) {}

    public function addVersion(Document $document, UploadedFile $file, User $user, ?string $note = null): DocumentVersion
    {
        $versionId = (string) Str::uuid7();
        $dek = random_bytes(32);
        $noncePrefix = random_bytes(8);
        $kekVersion = $this->keys->activeVersionId();
        $path = '';

        try {
            return DB::transaction(function () use ($document, $file, $user, $note, $versionId, $dek, $noncePrefix, $kekVersion, &$path) {
                // Locks the document so two uploads can't take one version number.
                Document::query()->whereKey($document->id)->lockForUpdate()->first();
                $number = (int) DocumentVersion::query()->where('document_id', $document->id)->max('version_number') + 1;
                $path = $this->pathFor($document, $number);

                $meta = $this->writeEncrypted($file, $path, $dek, $noncePrefix, $versionId);

                $version = new DocumentVersion;
                $version->forceFill([
                    'id' => $versionId,
                    'document_id' => $document->id,
                    'version_number' => $number,
                    'storage_disk' => self::DISK,
                    'storage_region' => (string) config('pocketnyaya.document_storage_region'),
                    'storage_path' => $path,
                    'original_filename_enc' => $this->cipher->encryptFilename($file->getClientOriginalName(), $dek, $versionId),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => $meta['size'],
                    'plaintext_mac' => $meta['mac'],
                    'chunk_size' => ChunkedCipher::CHUNK_SIZE,
                    'nonce_prefix' => $noncePrefix,
                    // No malware scanner is connected yet, and this says so honestly.
                    'scan_status' => 'skipped',
                    'uploaded_by' => $user->id,
                    'change_note' => $note,
                ])->save();

                $key = new DocumentKey;
                $key->forceFill([
                    'document_version_id' => $versionId,
                    'kek_version_id' => $kekVersion,
                    'wrapped_dek' => $this->keys->wrap($dek, $kekVersion, $this->keyContext($versionId)),
                ])->save();

                $this->log($document, 'upload', $user, $versionId);

                return $version;
            });
        } catch (\Throwable $e) {
            if ($path !== '') {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $e;
        } finally {
            sodium_memzero($dek);
        }
    }

    /** Decrypts while streaming to the browser. The caller has already authorised this. */
    public function download(Document $document, DocumentVersion $version, User $user): StreamedResponse
    {
        $dek = $this->unwrap($version);
        $filename = $this->cipher->decryptFilename($version->original_filename_enc, $dek, $version->id);
        $this->log($document, 'download', $user, $version->id);

        return response()->streamDownload(function () use ($version, $dek) {
            $this->decryptTo($version, $dek, function (string $chunk) {
                echo $chunk;
                flush();
            });
        }, $filename, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Decrypts into memory, for tests and small internal uses. */
    public function readContents(DocumentVersion $version): string
    {
        $contents = '';
        $this->decryptTo($version, $this->unwrap($version), function (string $chunk) use (&$contents) {
            $contents .= $chunk;
        });

        return $contents;
    }

    public function originalFilename(DocumentVersion $version): ?string
    {
        if ($version->isShredded()) {
            return null;
        }

        return $this->cipher->decryptFilename($version->original_filename_enc, $this->unwrap($version), $version->id);
    }

    /** Crypto-shredding: the file becomes permanently unreadable (C31). */
    public function shred(DocumentVersion $version, string $reason): void
    {
        DB::table('document_keys')->where('document_version_id', $version->id)->whereNull('destroyed_at')
            ->update(['wrapped_dek' => null, 'destroyed_at' => now(), 'destroyed_reason' => $reason]);
    }

    /** Re-wraps data keys with the active master key. File contents aren't touched. */
    public function rewrap(int $limit = 500): int
    {
        $active = $this->keys->activeVersionId();
        $done = 0;

        DocumentKey::query()->whereNull('destroyed_at')->where('kek_version_id', '<>', $active)->limit($limit)->get()
            ->each(function (DocumentKey $key) use ($active, &$done) {
                $context = $this->keyContext($key->document_version_id);
                $dek = $this->keys->unwrap((string) $key->wrapped_dek, (int) $key->kek_version_id, $context);
                $key->forceFill(['kek_version_id' => $active, 'wrapped_dek' => $this->keys->wrap($dek, $active, $context), 'rewrapped_at' => now()])->save();
                sodium_memzero($dek);
                $done++;
            });

        return $done;
    }

    public function log(Document $document, string $action, User $user, ?string $versionId = null): void
    {
        $request = request();

        DocumentAccessLog::query()->create([
            'document_id' => $document->id,
            'version_id' => $versionId,
            'user_id' => $user->id,
            'tenant_id' => $document->tenant_id,
            'action' => $action,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
    }

    private function unwrap(DocumentVersion $version): string
    {
        $key = $version->key;
        if ($key === null || $key->wrapped_dek === null) {
            throw new RuntimeException('This file has been permanently erased.');
        }

        return $this->keys->unwrap($key->wrapped_dek, (int) $key->kek_version_id, $this->keyContext($version->id));
    }

    /** @param callable(string): void $sink */
    private function decryptTo(DocumentVersion $version, string $dek, callable $sink): void
    {
        $stream = Storage::disk($version->storage_disk)->readStream($version->storage_path);
        if ($stream === null) {
            throw new RuntimeException('The encrypted file is missing.');
        }

        try {
            $this->cipher->decrypt($stream, $sink, $dek, (string) $version->nonce_prefix, $version->id, $version->plaintext_mac);
        } finally {
            fclose($stream);
            sodium_memzero($dek);
        }
    }

    /** @return array{mac: string, size: int} */
    private function writeEncrypted(UploadedFile $file, string $path, string $dek, string $noncePrefix, string $versionId): array
    {
        $input = fopen($file->getRealPath(), 'rb');
        $temp = tmpfile();
        if ($input === false || $temp === false) {
            throw new RuntimeException('Could not read the upload.');
        }

        try {
            $meta = $this->cipher->encrypt($input, $temp, $dek, $noncePrefix, $versionId);
            rewind($temp);
            Storage::disk(self::DISK)->writeStream($path, $temp);

            return $meta;
        } finally {
            fclose($input);
            fclose($temp);
        }
    }

    private function pathFor(Document $document, int $number): string
    {
        return match ($document->scope) {
            'tenant' => "tenants/{$document->tenant_id}/docs/{$document->id}/{$number}.bin",
            'personal' => "users/{$document->owner_user_id}/vault/{$document->id}/{$number}.bin",
            default => "platform/docs/{$document->id}/{$number}.bin",
        };
    }

    private function keyContext(string $versionId): string
    {
        return 'pocketnyaya/document-key/'.$versionId;
    }
}
