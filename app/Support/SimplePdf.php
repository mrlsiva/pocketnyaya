<?php

namespace App\Support;

/**
 * A minimal text-only PDF writer, so the unsigned settlement needs no new package.
 * It wraps to A4 and uses a standard font, which covers Western characters only:
 * anything else prints as "?". Indian-language output needs a proper PDF library.
 */
final class SimplePdf
{
    private const PER_PAGE = 50;

    /** @param list<string> $paragraphs */
    public static function render(string $title, array $paragraphs): string
    {
        $lines = [];
        foreach ($paragraphs as $paragraph) {
            foreach (explode("\n", wordwrap($paragraph, 88, "\n", true)) as $line) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        $objects = [];
        $pages = array_chunk($lines, self::PER_PAGE);
        $kids = [];
        $objectId = 4;
        foreach ($pages as $index => $pageLines) {
            $stream = 'BT /F1 14 Tf 50 790 Td ('.self::text($index === 0 ? $title : '').") Tj ET\nBT /F1 10 Tf 13 TL 50 765 Td\n";
            foreach ($pageLines as $line) {
                $stream .= '('.self::text($line).") Tj T*\n";
            }
            $stream .= 'ET';
            $pageId = $objectId++;
            $contentId = $objectId++;
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private static function text(string $text): string
    {
        $encoded = (string) @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }
}
