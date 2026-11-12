<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class CheckDeploymentCommandTest extends TestCase
{
    public function test_developer_checkout_is_rejected_without_connecting(): void
    {
        config(['deployment.environment' => 'developer']);
        DB::shouldReceive('connection')->never();
        $this->artisan('caselantern:check-deployment caselantern_integration')->assertFailed();
    }

    public function test_developer_database_name_is_rejected_without_connecting(): void
    {
        config(['deployment.environment' => 'integration']);
        DB::shouldReceive('connection')->never();
        $this->artisan('caselantern:check-deployment caselantern_alex')->assertFailed();
    }

    public function test_both_actual_connections_must_target_the_integration_database(): void
    {
        config(['deployment.environment' => 'integration', 'database.default' => 'pgsql']);
        foreach (['pgsql', 'pgsql_migrator'] as $name) {
            $connection = Mockery::mock(Connection::class);
            $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
            $connection->shouldReceive('getDatabaseName')->once()->andReturn('caselantern_integration');
            $connection->shouldReceive('selectOne')->with('SELECT current_database() AS name')
                ->once()->andReturn((object) ['name' => 'caselantern_integration']);
            DB::shouldReceive('connection')->with($name)->once()->andReturn($connection);
        }
        $this->artisan('caselantern:check-deployment caselantern_integration')->assertSuccessful();
    }

    public function test_live_database_mismatch_is_rejected(): void
    {
        config(['deployment.environment' => 'integration', 'database.default' => 'pgsql']);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('getDatabaseName')->andReturn('caselantern_integration');
        $connection->shouldReceive('selectOne')->andReturn((object) ['name' => 'caselantern_alex']);
        DB::shouldReceive('connection')->with('pgsql')->once()->andReturn($connection);
        DB::shouldReceive('connection')->with('pgsql_migrator')->never();
        $this->artisan('caselantern:check-deployment caselantern_integration')->assertFailed();
    }
}
