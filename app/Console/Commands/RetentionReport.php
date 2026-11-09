<?php

namespace App\Console\Commands;

use App\Services\RetentionService;
use Illuminate\Console\Command;

/** Nightly dry run: lists what approved retention rules make due. It changes nothing. */
class RetentionReport extends Command
{
    protected $signature = 'pocketnyaya:retention-report';

    protected $description = 'Reports what approved retention rules make due (dry run; disposal needs two people)';

    public function handle(RetentionService $retention): int
    {
        $this->info('Report batches written: '.$retention->report());

        return self::SUCCESS;
    }
}
