<?php

use Illuminate\Support\Facades\Schedule;

// Anchors yesterday's audit rows with a hash for each practice, so later tampering shows.
// Production runs `php artisan schedule:run` every minute from cron (docs/deployment).
Schedule::command('pocketnyaya:audit-digest')->dailyAt('00:30')->timezone('Asia/Kolkata')->withoutOverlapping()->onOneServer();
