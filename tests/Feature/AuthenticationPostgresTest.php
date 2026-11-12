<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

class AuthenticationPostgresTest extends TestCase
{
    private ?TenantFixtures $fixtures = null;

    private array $pair;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('CASELANTERN_TEST_DATABASE');
        if (! $database) {
            if (getenv('CI') === 'true') {
                throw new RuntimeException('CI requires CASELANTERN_TEST_DATABASE.');
            }
            $this->markTestSkipped('Set owner/runtime credentials and CASELANTERN_TEST_DATABASE.');
        }
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException('Authentication tests require a disposable _test database.');
        }
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => $database,
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql_migrator.database' => $database,
            'tenancy.base_domain' => 'example.test',
        ]);
        DB::purge('pgsql');
        DB::purge('pgsql_migrator');
        $identity = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertFalse(DB::selectOne("SELECT tableowner = current_user AS owns FROM pg_tables WHERE schemaname = 'public' AND tablename = 'users'")->owns);
        $this->fixtures = new TenantFixtures(DB::connection('pgsql_migrator'));
        $this->pair = $this->fixtures->createPair();
    }

    protected function tearDown(): void
    {
        try {
            $this->travelBack();
            $this->fixtures?->cleanUp();
        } finally {
            DB::purge('pgsql');
            DB::purge('pgsql_migrator');
            parent::tearDown();
        }
    }

    private function url(string $path, string $tenant = 'a'): string
    {
        return 'http://'.$this->pair[$tenant]['slug'].'.example.test/api/v1/'.$path;
    }

    private function login(string $tenant = 'a'): string
    {
        return $this->postJson($this->url('login', $tenant), [
            'email' => 'SHARED@example.test', 'password' => 'Synthetic fixture password only',
        ])->assertOk()->assertJsonPath('token_type', 'Bearer')->json('access_token');
    }

    public function test_login_me_hash_storage_and_current_token_logout(): void
    {
        $first = $this->login();
        $second = $this->login();
        $this->assertNotSame($first, $second);
        $this->fixtures->inTenant(DB::connection(), $this->pair['a'], function () use ($first): void {
            $row = DB::table('access_tokens')->where('token_hash', hash('sha256', $first))->first();
            $this->assertNotNull($row);
            $this->assertNotSame($first, $row->token_hash);
            $this->assertSame(172800, strtotime($row->expires_at) - strtotime($row->created_at));
        });
        $headers = ['Authorization' => 'Bearer '.$first];
        $this->getJson($this->url('me'), $headers)->assertOk()
            ->assertJsonPath('user.id', $this->pair['a']['user_id'])
            ->assertJsonMissingPath('user.password');
        $this->postJson($this->url('logout'), [], $headers)->assertNoContent();
        $this->getJson($this->url('me'), $headers)->assertUnauthorized();
        $this->getJson($this->url('me'), ['Authorization' => 'Bearer '.$second])->assertOk();
        $this->assertContextCleared();
    }

    public function test_expiry_is_absolute_at_48_hours_and_activity_does_not_extend_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        $token = $this->login();
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->travel(47)->hours();
        $this->getJson($this->url('me'), $headers)->assertOk();
        $this->travel(1)->hours();
        $this->getJson($this->url('me'), $headers)->assertUnauthorized();
    }

    public function test_tokens_and_duplicate_email_accounts_stay_in_their_tenant(): void
    {
        $a = $this->login();
        $b = $this->login('b');
        $this->getJson($this->url('me', 'b'), ['Authorization' => 'Bearer '.$a])->assertUnauthorized();
        $this->getJson($this->url('me', 'b'), ['Authorization' => 'Bearer '.$b])->assertOk()
            ->assertJsonPath('user.id', $this->pair['b']['user_id']);
        $this->getJson($this->url('me'), ['Authorization' => 'Bearer '.$a])->assertOk();
        $this->assertContextCleared();
    }

    public function test_disabled_deleted_and_password_changed_accounts_cannot_use_existing_tokens(): void
    {
        $token = $this->login();
        foreach ([['active' => false], ['active' => true, 'deleted_at' => now()],
            ['deleted_at' => null, 'password' => Hash::make('Changed fixture password')]] as $changes) {
            $this->fixtures->inTenant(DB::connection('pgsql_migrator'), $this->pair['a'], function () use ($changes): void {
                DB::connection('pgsql_migrator')->table('users')->where('id', $this->pair['a']['user_id'])->update($changes);
            });
            $this->getJson($this->url('me'), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
            $this->postJson($this->url('login'), ['email' => 'shared@example.test', 'password' => 'Synthetic fixture password only'])
                ->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);
        }
    }

    public function test_disabled_tenant_and_untrusted_hosts_are_rejected(): void
    {
        $token = $this->login();
        foreach (['example.test', 'a.b.example.test', 'acme.example.test.evil.test', 'missing.example.test'] as $host) {
            $this->getJson('http://'.$host.'/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertNotFound();
        }
        $this->fixtures->inTenant(DB::connection('pgsql_migrator'), $this->pair['a'], function (): void {
            DB::connection('pgsql_migrator')->table('tenants')->where('id', $this->pair['a']['tenant_id'])->update(['active' => false]);
        });
        $this->getJson($this->url('me'), ['Authorization' => 'Bearer '.$token])->assertNotFound();
    }

    public function test_validation_generic_errors_and_login_rate_limit(): void
    {
        $this->postJson($this->url('login'), [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        foreach (['shared@example.test', 'unknown@example.test'] as $email) {
            $this->postJson($this->url('login'), ['email' => $email, 'password' => 'wrong'])
                ->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);
        }
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson($this->url('login'), ['email' => 'shared@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson($this->url('login'), ['email' => 'shared@example.test', 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertContextCleared();
    }

    public function test_missing_malformed_and_query_string_tokens_are_not_authenticated(): void
    {
        $token = $this->login();
        $this->get($this->url('me'))->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->getJson($this->url('me').'?access_token='.$token)->assertUnauthorized();
        $this->getJson($this->url('me'), ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
        $this->postJson($this->url('logout'))->assertUnauthorized();
    }

    public function test_failed_request_rolls_back_and_clears_context(): void
    {
        Route::middleware(['api', 'auth:api'])->post('/api/v1/test-failure', function (): void {
            DB::table('users')->where('id', $this->pair['a']['user_id'])->update(['name' => 'Must roll back']);
            abort(422, 'Synthetic failure');
        });
        $token = $this->login();
        $this->postJson($this->url('test-failure'), [], ['Authorization' => 'Bearer '.$token])->assertUnprocessable();
        $this->getJson($this->url('me'), ['Authorization' => 'Bearer '.$token])->assertOk()
            ->assertJsonPath('user.name', 'Synthetic administrator');
        $this->assertContextCleared();
    }

    public function test_lookup_identity_has_only_the_narrow_read_privileges(): void
    {
        $row = DB::selectOne("SELECT
            has_table_privilege('caselantern_tenant_lookup', 'public.users', 'SELECT') AS users,
            has_table_privilege('caselantern_tenant_lookup', 'public.tenants', 'UPDATE') AS writes,
            has_column_privilege('caselantern_tenant_lookup', 'public.tenants', 'name', 'SELECT') AS names,
            has_schema_privilege('caselantern_tenant_lookup', 'public', 'CREATE') AS ddl");
        foreach (['users', 'writes', 'names', 'ddl'] as $field) {
            $this->assertFalse($row->$field);
        }
        $this->assertSame(['id', 'active'], array_keys((array) DB::selectOne('SELECT * FROM public.lookup_tenant(?)', [$this->pair['a']['slug']])));
        $this->assertContextCleared();
    }

    private function assertContextCleared(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('access_tokens')->count());
        foreach (['app.tenant_id', 'app.user_id'] as $key) {
            $this->assertSame('', DB::selectOne("SELECT coalesce(current_setting(?, true), '') AS value", [$key])->value);
        }
    }
}
