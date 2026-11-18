<?php

namespace Tests\Support;

use App\Auth\BearerTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

abstract class ApiPostgresTest extends TestCase
{
    protected ?TenantFixtures $fixtures = null;

    protected array $pair;

    protected array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('CASELANTERN_TEST_DATABASE');
        if (! $database) {
            if (getenv('CI') === 'true') {
                throw new RuntimeException('CI requires PostgreSQL integration tests.');
            }
            $this->markTestSkipped('Requires a disposable PostgreSQL database.');
        }
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException('Integration tests require a disposable _test database.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => $database, 'database.connections.pgsql.url' => null, 'database.connections.pgsql_migrator.database' => $database, 'tenancy.base_domain' => 'example.test']);
        DB::purge('pgsql');
        DB::purge('pgsql_migrator');
        $identity = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertFalse(DB::selectOne("SELECT tableowner = current_user AS owns FROM pg_tables WHERE schemaname = 'public' AND tablename = 'users'")->owns);
        $this->fixtures = new TenantFixtures(DB::connection('pgsql_migrator'));
        $this->pair = $this->fixtures->createPair();
        $token = $this->fixtures->inTenant(DB::connection(), $this->pair['a'], fn () => app(BearerTokens::class)->issue(User::findOrFail($this->pair['a']['user_id'])));
        $this->headers = ['Authorization' => 'Bearer '.$token['access_token']];
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->fixtures?->cleanUp();
        } finally {
            DB::purge('pgsql');
            DB::purge('pgsql_migrator');
            parent::tearDown();
        }
    }

    protected function url(string $path, string $tenant = 'a'): string
    {
        return 'http://'.$this->pair[$tenant]['slug'].'.example.test/api/v1/'.$path;
    }

    protected function role(string $role): void
    {
        DB::selectOne("SELECT set_config('app.tenant_id', ?, true)", [$this->pair['a']['tenant_id']]);
        DB::table('users')->where('id', $this->pair['a']['user_id'])->update(['role' => $role]);
    }
}
