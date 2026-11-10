<?php

/*
 * Outside services (Phase 4). Every one defaults to "none": no network calls, and no data
 * leaves the platform. A real driver is added only after its API access, cost, data
 * location (India) and legal requirements are checked and approved. Credentials belong
 * in .env, never in code or the database.
 *
 * Providers chosen by the owner on 2026-11-10: Anthropic Claude (ai), Razorpay (payments)
 * and Jitsi (video). SMS and eSign stay switched off for now.
 */
return [
    'payments' => [
        'driver' => env('PAYMENTS_DRIVER', 'none'),
        'razorpay' => [
            'key_id' => env('RAZORPAY_KEY_ID'),
            'key_secret' => env('RAZORPAY_KEY_SECRET'),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
            'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),
            'link_expiry_days' => (int) env('RAZORPAY_LINK_EXPIRY_DAYS', 15),
        ],
    ],
    'video' => [
        'driver' => env('VIDEO_DRIVER', 'none'),
        'jitsi' => [
            // meet.jit.si is for testing only. Production uses a self-hosted server in India.
            'base_url' => env('JITSI_BASE_URL', 'https://meet.jit.si'),
            'room_prefix' => env('JITSI_ROOM_PREFIX', 'PocketNyaya'),
        ],
    ],
    'esign' => ['driver' => env('ESIGN_DRIVER', 'none')],
    'court_data' => ['driver' => env('COURT_DATA_DRIVER', 'none')],
    'ai' => [
        'driver' => env('AI_DRIVER', 'none'),
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
            'effort' => env('ANTHROPIC_EFFORT', 'medium'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
            'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
        ],
    ],
    'messaging' => ['driver' => env('MESSAGING_DRIVER', 'none')],
];
