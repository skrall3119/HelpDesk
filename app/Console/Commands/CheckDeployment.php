<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckDeployment extends Command
{
    protected $signature = 'caselantern:check-deployment {database}';

    protected $description = 'Verify integration identity and database targets before deployment';

    public function handle(): int
    {
        $expected = $this->argument('database');
        if (config('deployment.environment') !== 'integration' || app()->environment('production')
            || ! is_string($expected) || ! preg_match('/^[a-z][a-z0-9_]*_integration$/D', $expected)) {
            $this->error('Deployment requires an integration checkout and a database ending in _integration.');

            return self::FAILURE;
        }

        foreach ([config('database.default'), 'pgsql_migrator'] as $name) {
            $connection = DB::connection($name);
            if ($connection->getDriverName() !== 'pgsql'
                || $connection->getDatabaseName() !== $expected
                || $connection->selectOne('SELECT current_database() AS name')->name !== $expected) {
                $this->error('Deployment database mismatch; no migrations were run.');

                return self::FAILURE;
            }
        }

        $this->info('Integration database targets verified.');

        return self::SUCCESS;
    }
}
