<?php

namespace App\Console\Commands;

use App\Services\Documents\LocalKeyProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateDocumentKey extends Command
{
    protected $signature = 'pocketnyaya:document-key {--rotate : Create a new key even if one is already active}';

    protected $description = 'Create the local development master key for document encryption';

    public function handle(LocalKeyProvider $keys): int
    {
        $active = DB::table('encryption_key_versions')->where(['purpose' => 'documents', 'status' => 'active'])->exists();
        if ($active && ! $this->option('rotate')) {
            $this->info('An active document key already exists.');

            return self::SUCCESS;
        }

        $id = $keys->createVersion();
        $this->info("Document key version {$id} is active. Back up its file separately from the database.");

        return self::SUCCESS;
    }
}
