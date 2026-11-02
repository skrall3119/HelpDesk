<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class TenantFixturesPostgresTest extends TestCase
{
    private ?TenantFixtures $fixtures = null;

    private Connection $runtime;

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('CASELANTERN_TEST_DATABASE');

        if ($database === false || $database === '') {
            if (getenv('CI') === 'true') {
                throw new RuntimeException('CI requires CASELANTERN_TEST_DATABASE; PostgreSQL checks must not skip.');
            }

            $this->markTestSkipped('Set CASELANTERN_TEST_DATABASE and owner/runtime credentials for PostgreSQL tests.');
        }

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException('Tenant fixture tests require a disposable database ending in _test.');
        }

        $runtimeConfig = config('database.connections.pgsql');
        $runtimeConfig['database'] = $database;
        $runtimeConfig['url'] = null;

        config([
            'database.connections.pgsql_migrator.database' => $database,
            'database.connections.pgsql_runtime_test' => $runtimeConfig,
        ]);

        DB::purge('pgsql_migrator');
        DB::purge('pgsql_runtime_test');
        $owner = DB::connection('pgsql_migrator');
        $this->runtime = DB::connection('pgsql_runtime_test');

        foreach ([$owner, $this->runtime] as $connection) {
            $identity = $connection->selectOne(
                'SELECT current_database() AS db, rolsuper, rolbypassrls FROM pg_catalog.pg_roles WHERE rolname = current_user'
            );
            $this->assertSame($database, $identity->db);
            $this->assertFalse($identity->rolsuper);
            $this->assertFalse($identity->rolbypassrls);
        }

        $privileges = $this->runtime->selectOne(
            "SELECT has_table_privilege(current_user, 'public.users', 'DELETE') AS can_delete,
                    has_table_privilege(current_user, 'public.users', 'TRUNCATE') AS can_truncate,
                    has_table_privilege(current_user, 'public.tenants', 'INSERT') AS can_provision"
        );
        $this->assertFalse($privileges->can_delete);
        $this->assertFalse($privileges->can_truncate);
        $this->assertFalse($privileges->can_provision);
        $this->fixtures = new TenantFixtures($owner);
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtures?->cleanUp();
        } finally {
            DB::purge('pgsql_migrator');
            DB::purge('pgsql_runtime_test');
            parent::tearDown();
        }
    }

    public function test_two_tenants_with_the_same_email_are_isolated_under_runtime_credentials(): void
    {
        $pair = $this->fixtures->createPair();
        $this->assertSame(0, $this->runtime->table('public.users')->count());

        foreach (['a', 'b'] as $label) {
            $this->fixtures->inTenant($this->runtime, $pair[$label], function (Connection $connection) use ($pair, $label): void {
                $users = $connection->table('public.users')->get();
                $this->assertCount(1, $users);
                $this->assertSame($pair[$label]['tenant_id'], (string) $users->first()->tenant_id);
                $this->assertSame('shared@example.test', $users->first()->email);
            });

            $this->assertSame(0, $this->runtime->table('public.users')->count());
        }
    }

    public function test_cross_tenant_write_is_rejected_and_failed_context_is_cleared(): void
    {
        $pair = $this->fixtures->createPair();

        try {
            $this->fixtures->inTenant($this->runtime, $pair['a'], function (Connection $connection) use ($pair): void {
                $connection->table('public.clients')->insert([
                    'tenant_id' => $pair['b']['tenant_id'],
                    'client_type' => 'business',
                    'name' => 'Forbidden fixture client',
                ]);
            });
            $this->fail('Cross-tenant write was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', (string) $exception->getCode());
        }

        $this->assertSame(0, $this->runtime->table('public.clients')->count());
        $this->assertSame('', $this->runtime->selectOne(
            "SELECT coalesce(current_setting('app.tenant_id', true), '') AS context"
        )->context);
    }
}
