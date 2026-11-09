<?php

namespace App\Integrations\Contracts;

/** Step 20. Reads public case status from NJDG or eCourts by CNR number. */
interface CourtDataProvider
{
    /** @return array{status: string, next_hearing: ?string, court: ?string} */
    public function caseStatus(string $cnr): array;
}
