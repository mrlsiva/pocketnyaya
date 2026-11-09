<?php

namespace App\Integrations;

/**
 * Step 19, first version: a local, rule-based masker. It runs on this server and sends
 * nothing anywhere. It catches common Indian identifiers and contact details by pattern.
 * It does not recognise names or addresses, so masked text still needs a person's review
 * before any use as training data. A stronger masker can replace it later behind this class.
 */
class PiiMasker
{
    /** @var array<string, string> Applied in this order: GSTIN contains a PAN, so it goes first. */
    private const PATTERNS = [
        'EMAIL' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        'GSTIN' => '/\b\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]\b/',
        'AADHAAR' => '/(?<!\d)[2-9]\d{3}[\s-]?\d{4}[\s-]?\d{4}(?!\d)/',
        'PAN' => '/\b[A-Z]{5}\d{4}[A-Z]\b/',
        'CNR' => '/\b[A-Z]{4}\d{12}\b/',
        'PHONE' => '/(?<!\d)(?:\+?91[\s-]?|0)?[6-9]\d{4}[\s-]?\d{5}(?!\d)/',
        'UPI' => '/\b[A-Za-z0-9._-]{2,}@[A-Za-z]{2,}\b/',
        'IFSC' => '/\b[A-Z]{4}0[A-Z0-9]{6}\b/',
        'ACCOUNT' => '/(?<!\d)\d{9,18}(?!\d)/',
    ];

    /** @return array{text: string, counts: array<string, int>} */
    public function mask(string $text): array
    {
        $counts = [];
        foreach (self::PATTERNS as $label => $pattern) {
            $text = (string) preg_replace_callback($pattern, function () use ($label, &$counts) {
                $counts[$label] = ($counts[$label] ?? 0) + 1;

                return '['.$label.']';
            }, $text);
        }

        return ['text' => $text, 'counts' => $counts];
    }
}
