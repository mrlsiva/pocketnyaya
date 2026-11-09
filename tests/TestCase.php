<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    /**
     * Runs before the test's transaction starts: migrations are DDL, and DDL
     * inside the transaction would commit that test's data.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        // Tests must never overwrite real development key files.
        config(['pocketnyaya.document_key_dir' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'pocketnyaya-test-keys']);

        // Tests must never touch the development database.
        $database = DB::connection()->getDatabaseName();
        if ($database !== 'pocket_test') {
            throw new RuntimeException("Tests must run against pocket_test, not {$database}.");
        }

        if (! self::$migrated) {
            Artisan::call('migrate', ['--force' => true]);
            self::$migrated = true;
        }

        return parent::setUpTraits();
    }
}
