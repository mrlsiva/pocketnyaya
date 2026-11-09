<?php

/*
 * Outside services (Phase 4). Every one defaults to "none": no network calls, and no data
 * leaves the platform. A real driver is added only after its API access, cost, data
 * location (India) and legal requirements are checked and approved. Credentials belong
 * in .env, never in code or the database.
 */
return [
    'payments' => ['driver' => env('PAYMENTS_DRIVER', 'none')],
    'video' => ['driver' => env('VIDEO_DRIVER', 'none')],
    'esign' => ['driver' => env('ESIGN_DRIVER', 'none')],
    'court_data' => ['driver' => env('COURT_DATA_DRIVER', 'none')],
    'ai' => ['driver' => env('AI_DRIVER', 'none')],
    'messaging' => ['driver' => env('MESSAGING_DRIVER', 'none')],
];
