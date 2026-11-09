<?php

namespace App\Support;

use InvalidArgumentException;

/** MY-10: record_hash v3, an exact tag-length-value encoding over ASCII fields. */
final class ConsentHash
{
    /**
     * @param  array{user_id: string, sequence_no: int|string, consent_purpose_id: string, action: string, channel: ?string, created_at: string, consent_key_id: string, evidence_commitment: string, prev_hash: ?string}  $r
     */
    public static function v3(array $r): string
    {
        $message = "PNCH\x03"
            .self::field(1, $r['user_id'])
            .self::field(2, (string) $r['sequence_no'])
            .self::field(3, $r['consent_purpose_id'])
            .self::field(4, $r['action'])
            .self::field(5, $r['channel'])
            .self::field(6, $r['created_at'])
            .self::field(7, $r['consent_key_id'])
            .self::field(8, $r['evidence_commitment'])
            .self::field(9, $r['prev_hash']);

        return hash('sha256', $message);
    }

    private static function field(int $tag, ?string $value): string
    {
        if ($value === null) {
            return chr($tag)."\x00";
        }
        if (preg_match('/[^\x00-\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Consent hash fields must be ASCII.');
        }

        return chr($tag)."\x01".pack('N', strlen($value)).$value;
    }
}
