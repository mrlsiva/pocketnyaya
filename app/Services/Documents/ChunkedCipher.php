<?php

namespace App\Services\Documents;

use RuntimeException;

/**
 * Approved file encryption (C7, C31): AES-256-GCM in 4 MB chunks. Each nonce is a
 * random 64-bit prefix plus a 32-bit counter. The authenticated data binds each
 * chunk to its version, position and finality, which blocks reordering,
 * truncation and swapping a file between documents.
 *
 * File layout: repeated [uint32 ciphertext length][16-byte tag][ciphertext].
 */
class ChunkedCipher
{
    public const CHUNK_SIZE = 4194304;

    private const CIPHER = 'aes-256-gcm';

    /**
     * @param  resource  $input
     * @param  resource  $output
     * @return array{mac: string, size: int}
     */
    public function encrypt($input, $output, string $dek, string $noncePrefix, string $versionId): array
    {
        $mac = hash_init('sha256', HASH_HMAC, $this->subKey($dek, 'plaintext-mac'));
        $size = 0;
        $index = 0;
        $current = $this->read($input);

        do {
            $next = $current === '' ? '' : $this->read($input);
            $isFinal = $next === '';
            hash_update($mac, $current);
            $size += strlen($current);

            $tag = '';
            $ciphertext = openssl_encrypt($current, self::CIPHER, $dek, OPENSSL_RAW_DATA, $this->nonce($noncePrefix, $index), $tag, $this->aad($versionId, $index, $isFinal), 16);
            if ($ciphertext === false) {
                throw new RuntimeException('Could not encrypt the file.');
            }
            if (fwrite($output, pack('N', strlen($ciphertext)).$tag.$ciphertext) === false) {
                throw new RuntimeException('Could not write the encrypted file.');
            }

            $current = $next;
            $index++;
        } while (! $isFinal);

        return ['mac' => hash_final($mac), 'size' => $size];
    }

    /**
     * Passes each decrypted chunk to the sink, then checks the plaintext MAC.
     * A tampered, reordered or truncated file throws instead of finishing.
     *
     * @param  resource  $input
     * @param  callable(string): void  $sink
     */
    public function decrypt($input, callable $sink, string $dek, string $noncePrefix, string $versionId, string $expectedMac): void
    {
        $mac = hash_init('sha256', HASH_HMAC, $this->subKey($dek, 'plaintext-mac'));
        $index = 0;

        while (true) {
            $header = fread($input, 20);
            if ($header === false || strlen($header) !== 20) {
                throw new RuntimeException('The encrypted file is damaged or incomplete.');
            }
            /** @var array{1: int} $length */
            $length = unpack('N', substr($header, 0, 4));
            $ciphertext = $length[1] === 0 ? '' : stream_get_contents($input, $length[1]);
            if ($ciphertext === false || strlen($ciphertext) !== $length[1]) {
                throw new RuntimeException('The encrypted file is damaged or incomplete.');
            }

            $isFinal = $this->atEnd($input);
            $plain = openssl_decrypt($ciphertext, self::CIPHER, $dek, OPENSSL_RAW_DATA, $this->nonce($noncePrefix, $index), substr($header, 4, 16), $this->aad($versionId, $index, $isFinal));
            if ($plain === false) {
                throw new RuntimeException('The encrypted file failed its integrity check.');
            }

            hash_update($mac, $plain);
            $sink($plain);
            $index++;

            if ($isFinal) {
                break;
            }
        }

        if (! hash_equals($expectedMac, hash_final($mac))) {
            throw new RuntimeException('The decrypted file failed its integrity check.');
        }
    }

    /** The original filename, readable only with this version's data key (C31). */
    public function encryptFilename(string $filename, string $dek, string $versionId): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($filename, self::CIPHER, $this->subKey($dek, 'filename'), OPENSSL_RAW_DATA, $nonce, $tag, 'filename'.$versionId, 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Could not encrypt the filename.');
        }

        return base64_encode($nonce.$tag.$ciphertext);
    }

    public function decryptFilename(string $encrypted, string $dek, string $versionId): string
    {
        $raw = (string) base64_decode($encrypted, true);
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->subKey($dek, 'filename'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'filename'.$versionId);
        if ($plain === false) {
            throw new RuntimeException('Could not read the filename.');
        }

        return $plain;
    }

    /** @param resource $input */
    private function read($input): string
    {
        $data = stream_get_contents($input, self::CHUNK_SIZE);

        return $data === false ? '' : $data;
    }

    /** @param resource $input */
    private function atEnd($input): bool
    {
        $byte = fread($input, 1);
        if ($byte === false || $byte === '') {
            return true;
        }
        fseek($input, -1, SEEK_CUR);

        return false;
    }

    private function nonce(string $prefix, int $index): string
    {
        return $prefix.pack('N', $index);
    }

    private function aad(string $versionId, int $index, bool $isFinal): string
    {
        return $versionId.'|'.$index.'|'.($isFinal ? '1' : '0');
    }

    private function subKey(string $dek, string $purpose): string
    {
        return hash_hkdf('sha256', $dek, 32, 'pocketnyaya/'.$purpose);
    }
}
