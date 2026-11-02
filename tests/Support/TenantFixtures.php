<?php

namespace Tests\Support;

use App\Actions\Tenants\ProvisionTenant;
use Illuminate\Database\Connection;
use RuntimeException;

/** Synthetic tenant/admin fixtures only; never invoke against application data. */
class TenantFixtures
{
    /** @var list<array{tenant_id: string, user_id: string, slug: string}> */
    private array $created = [];

    public function __construct(private Connection $owner)
    {
        if ($owner->getName() !== 'pgsql_migrator') {
            throw new RuntimeException('Tenant fixtures must use the dedicated migration connection.');
        }
    }

    /** @return array{a: array{tenant_id: string, user_id: string, slug: string}, b: array{tenant_id: string, user_id: string, slug: string}} */
    public function createPair(): array
    {
        $database = $this->owner->getDatabaseName();

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException('Tenant fixtures require a dedicated database ending in _test.');
        }

        $pair = [];

        foreach (['a', 'b'] as $label) {
            $fixture = app(ProvisionTenant::class)->execute([
                'name' => 'Fixture tenant '.strtoupper($label),
                'slug' => 'fixture-'.$label.'-'.bin2hex(random_bytes(8)),
                'admin_name' => 'Synthetic administrator',
                'admin_email' => 'shared@example.test',
                'password' => 'Synthetic fixture password only',
                'password_confirmation' => 'Synthetic fixture password only',
            ]);
            $this->created[] = $fixture;
            $pair[$label] = $fixture;
        }

        return $pair;
    }

    /**
     * @param  array{tenant_id: string, user_id: string, slug: string}  $tenant
     */
    public function inTenant(Connection $connection, array $tenant, callable $callback): mixed
    {
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Fixture context requires a standalone transaction.');
        }

        if ($connection->getDatabaseName() !== $this->owner->getDatabaseName()) {
            throw new RuntimeException('Fixture context cannot target a different database.');
        }

        return $connection->transaction(function () use ($connection, $tenant, $callback): mixed {
            $connection->selectOne("SELECT set_config('app.tenant_id', ?, true)", [$tenant['tenant_id']]);
            $connection->selectOne("SELECT set_config('app.user_id', ?, true)", [$tenant['user_id']]);

            return $callback($connection);
        });
    }

    public function cleanUp(): void
    {
        // Account fixtures do not erase ticket history or disable append-only rules.
        // Tests adding domain data must roll back those writes before cleanup.
        foreach (array_reverse($this->created) as $tenant) {
            $this->inTenant($this->owner, $tenant, function (Connection $connection) use ($tenant): void {
                $connection->table('public.users')->where('tenant_id', $tenant['tenant_id'])->delete();
                $connection->table('public.tenants')->where('id', $tenant['tenant_id'])->delete();
            });
        }

        $this->created = [];
    }
}
