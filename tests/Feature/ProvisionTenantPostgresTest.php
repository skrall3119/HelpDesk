<?php

namespace Tests\Feature;

use App\Actions\Tenants\ProvisionTenant;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ProvisionTenantPostgresTest extends TestCase
{
    private ?Connection $connection = null;

    /** @var list<string> */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('CASELANTERN_TEST_DATABASE');

        if ($database === false || $database === '') {
            $this->markTestSkipped('Set CASELANTERN_TEST_DATABASE to a migrated disposable PostgreSQL database.');
        }

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException('Provisioning tests require a dedicated database ending in _test.');
        }

        config(['database.connections.pgsql_migrator.database' => $database]);
        DB::purge('pgsql_migrator');
        $this->connection = DB::connection('pgsql_migrator');
        $identity = $this->connection->selectOne(
            'SELECT current_database() AS db, rolsuper, rolbypassrls FROM pg_catalog.pg_roles WHERE rolname = current_user'
        );
        $this->assertSame($database, $identity->db);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection !== null) {
                foreach ($this->tenantIds as $tenantId) {
                    $this->connection->transaction(function () use ($tenantId): void {
                        $this->context($tenantId);
                        $this->connection->table('public.users')->where('tenant_id', $tenantId)->delete();
                        $this->connection->table('public.tenants')->where('id', $tenantId)->delete();
                    });
                }
            }
        } finally {
            DB::purge('pgsql_migrator');
            parent::tearDown();
        }
    }

    public function test_it_creates_a_hashed_administrator_and_preserves_tenant_isolation(): void
    {
        $input = $this->input();
        $first = $this->provision($input);
        $second = $this->provision($this->input()); // Same email, independent account.

        $this->connection->transaction(function () use ($first, $second, $input): void {
            $this->context($first['tenant_id']);
            $user = $this->connection->table('public.users')->where('id', $first['user_id'])->first();
            $this->assertSame('Alex Smith', $user->name);
            $this->assertSame('admin@example.com', $user->email);
            $this->assertSame('administrator', $user->role);
            $this->assertTrue($user->active);
            $this->assertNotSame($input['password'], $user->password);
            $this->assertTrue(Hash::check($input['password'], $user->password));
            $this->assertSame(0, $this->connection->table('public.users')->where('tenant_id', $second['tenant_id'])->count());
            $this->assertSame(1, $this->connection->table('public.tenants')->count());
        });

        $this->assertSame('', $this->connection->selectOne(
            "SELECT coalesce(current_setting('app.tenant_id', true), '') AS context"
        )->context);
        $this->assertSame(0, $this->connection->table('public.users')->count());
    }

    public function test_duplicate_slug_cannot_change_an_existing_administrator(): void
    {
        $input = $this->input();
        $existing = $this->provision($input);

        try {
            app(ProvisionTenant::class)->execute(array_replace($input, ['admin_name' => 'Unwanted replacement']));
            $this->fail('Duplicate slug was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', (string) $exception->getCode());
        }

        $this->connection->transaction(function () use ($existing): void {
            $this->context($existing['tenant_id']);
            $this->assertSame(1, $this->connection->table('public.users')->count());
            $this->assertSame('Alex Smith', $this->connection->table('public.users')->value('name'));
        });
    }

    public function test_administrator_insert_failure_rolls_back_the_tenant(): void
    {
        $allocatedId = null;
        $this->connection->beforeExecuting(function (string $query, array $bindings) use (&$allocatedId): void {
            if (str_contains($query, "set_config('app.tenant_id', ?, true)")) {
                $allocatedId = (string) $bindings[0];
            }

            if (str_starts_with($query, 'insert into "public"."users"')) {
                throw new RuntimeException('Simulated administrator insert failure.');
            }
        });

        try {
            app(ProvisionTenant::class)->execute($this->input());
            $this->fail('Simulated write failure did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated administrator insert failure.', $exception->getMessage());
        }

        $this->assertNotNull($allocatedId);
        $this->tenantIds[] = $allocatedId;
        $this->connection->transaction(function () use ($allocatedId): void {
            $this->context($allocatedId);
            $this->assertSame(0, $this->connection->table('public.tenants')->count());
            $this->assertSame(0, $this->connection->table('public.users')->count());
        });
    }

    private function context(string $tenantId): void
    {
        $this->connection->selectOne("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
    }

    private function provision(array $input): array
    {
        $result = app(ProvisionTenant::class)->execute($input);
        $this->tenantIds[] = $result['tenant_id'];

        return $result;
    }

    private function input(): array
    {
        return [
            'name' => 'Provisioning test', 'slug' => 'provision-'.bin2hex(random_bytes(8)),
            'admin_name' => ' Alex Smith ', 'admin_email' => ' ADMIN@example.com ',
            'password' => 'Only a synthetic test password',
            'password_confirmation' => 'Only a synthetic test password',
        ];
    }
}
