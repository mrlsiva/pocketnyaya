<?php

namespace App\Integrations\Contracts;

/** Steps 17 and 18. Drafts and summaries are only a starting point for an advocate to check. */
interface AiDrafting
{
    /** @param  array<string, string>  $facts */
    public function draft(string $documentType, array $facts): string;

    public function summarise(string $text): string;
}
