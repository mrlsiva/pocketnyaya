<?php

namespace App\Services\Documents;

/**
 * The master key boundary (C7). The master key never enters the database, the
 * repository or database backups. Production uses a KMS implementation in an
 * Indian region; development uses LocalKeyProvider.
 */
interface KeyProvider
{
    /** The active key version for a purpose: documents, or journal for consent keys. */
    public function activeVersionId(string $purpose = 'documents'): int;

    /** Encrypts a data key. The context binds it to one document version. */
    public function wrap(string $dek, int $versionId, string $context): string;

    public function unwrap(string $wrapped, int $versionId, string $context): string;
}
