<?php

namespace App\Integrations\Contracts;

use App\Models\OdrSession;

/** Step 23. Creates a private meeting for a scheduled session. */
interface VideoProvider
{
    /** @return string the meeting reference stored on the session */
    public function createMeeting(OdrSession $session): string;
}
