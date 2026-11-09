<?php

use Illuminate\Support\Facades\Schedule;

// Anchors yesterday's audit rows with a hash for each practice, so later tampering shows.
// Production runs `php artisan schedule:run` every minute from cron (docs/deployment).
// Dry run of approved retention rules. Disposal still needs one person to propose and another to approve.
Schedule::command('pocketnyaya:retention-report')->dailyAt('01:00')->timezone('Asia/Kolkata')->withoutOverlapping()->onOneServer();

Schedule::command('pocketnyaya:audit-digest')->dailyAt('00:30')->timezone('Asia/Kolkata')->withoutOverlapping()->onOneServer();
