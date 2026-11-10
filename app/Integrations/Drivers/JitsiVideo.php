<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\VideoProvider;
use App\Integrations\IntegrationNotConfigured;
use App\Models\OdrSession;
use Illuminate\Support\Str;

/**
 * Step 23 with Jitsi. It makes a long random room name, so the link can't be guessed and
 * carries nothing about the dispute. No call is made to Jitsi: the room exists once
 * someone opens the link. Use meet.jit.si only for testing; production uses a self-hosted
 * Jitsi server in India, with the lobby or room passwords switched on there.
 */
class JitsiVideo implements VideoProvider
{
    public function createMeeting(OdrSession $session): string
    {
        $base = rtrim((string) config('integrations.video.jitsi.base_url'), '/');
        if (! str_starts_with($base, 'https://')) {
            throw new IntegrationNotConfigured('Video calls');
        }
        $prefix = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) config('integrations.video.jitsi.room_prefix', 'PocketNyaya'));

        return $base.'/'.($prefix !== '' ? $prefix : 'PocketNyaya').'-'.Str::random(32);
    }
}
