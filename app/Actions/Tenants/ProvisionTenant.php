<?php

namespace App\Actions\Tenants;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class ProvisionTenant
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{tenant_id: string, user_id: string, slug: string}
     */
    public function execute(array $input): array
    {
        foreach (['name', 'slug', 'admin_name', 'admin_email'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }

        if (is_string($input['admin_email'] ?? null)) {
            $input['admin_email'] = Str::lower($input['admin_email']);
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => [
                'required', 'string', 'min:12', 'confirmed',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                },
            ],
        ])->validate();

        if (! is_string(config('database.connections.pgsql_migrator.username'))
            || trim(config('database.connections.pgsql_migrator.username')) === '') {
            throw new RuntimeException('Set DB_MIGRATION_USERNAME and DB_MIGRATION_PASSWORD for this command.');
        }

        $connection = DB::connection('pgsql_migrator');

        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Provisioning needs a standalone PostgreSQL migration connection.');
        }

        // Require the actual table owner; never silently fall back to the web role.
        $owned = $connection->selectOne(
            "SELECT count(*) AS total FROM pg_catalog.pg_tables
             WHERE schemaname = 'public' AND tablename IN ('tenants', 'users')
             AND tableowner = current_user"
        );

        if ((int) $owned->total !== 2) {
            throw new RuntimeException('The provisioning connection must own the tenants and users tables.');
        }

        $passwordHash = Hash::make($data['password']);

        return $connection->transaction(function () use ($connection, $data, $passwordHash): array {
            // Reserve the identity first so the INSERT can satisfy FORCE RLS.
            // Sequence gaps on failed attempts are expected; IDs are never reused.
            $tenantId = (string) $connection->selectOne(
                "SELECT nextval(pg_get_serial_sequence('public.tenants', 'id')) AS id"
            )->id;

            $connection->selectOne("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
            $connection->selectOne("SELECT set_config('app.user_id', '', true)");

            $connection->insert(
                'INSERT INTO public.tenants (id, name, slug) OVERRIDING SYSTEM VALUE VALUES (?, ?, ?)',
                [$tenantId, $data['name'], $data['slug']]
            );

            $userId = $connection->table('public.users')->insertGetId([
                'tenant_id' => $tenantId,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => $passwordHash,
                'role' => 'administrator',
                'active' => true,
            ]);

            return ['tenant_id' => $tenantId, 'user_id' => (string) $userId, 'slug' => $data['slug']];
        });
    }
}
