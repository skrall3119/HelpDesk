<?php

namespace Tests\Feature;

use App\Actions\Tenants\ProvisionTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProvisionTenantCommandTest extends TestCase
{
    public function test_password_is_prompted_and_not_exposed_as_an_option(): void
    {
        $this->mock(ProvisionTenant::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->with([
                'name' => 'Acme', 'slug' => 'acme', 'admin_name' => 'Alex',
                'admin_email' => 'admin@example.com', 'password' => 'A secret password',
                'password_confirmation' => 'A secret password',
            ])->andReturn(['tenant_id' => '10', 'user_id' => '20', 'slug' => 'acme']);
        });

        $this->artisan('caselantern:provision-tenant', [
            '--name' => 'Acme', '--slug' => 'acme', '--admin-name' => 'Alex',
            '--admin-email' => 'admin@example.com',
        ])
            ->expectsQuestion('Administrator password (12+ characters, at most 72 bytes)', 'A secret password')
            ->expectsQuestion('Confirm administrator password', 'A secret password')
            ->expectsOutput('Tenant acme created (tenant 10, administrator 20).')
            ->doesntExpectOutput('A secret password')
            ->assertExitCode(0);
    }

    public function test_noninteractive_mode_cannot_create_an_account_without_a_password_prompt(): void
    {
        DB::shouldReceive('connection')->never();

        $this->artisan('caselantern:provision-tenant', ['--no-interaction' => true])
            ->expectsOutput('Run interactively: the administrator password must be entered at a hidden prompt.')
            ->assertExitCode(1);
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected_before_any_database_access(array $override): void
    {
        DB::shouldReceive('connection')->never();
        $this->expectException(ValidationException::class);

        app(ProvisionTenant::class)->execute(array_replace([
            'name' => 'Acme', 'slug' => 'acme', 'admin_name' => 'Alex',
            'admin_email' => 'admin@example.com', 'password' => 'A secret password',
            'password_confirmation' => 'A secret password',
        ], $override));
    }

    public static function invalidInput(): array
    {
        return [
            'blank organization' => [['name' => '  ']],
            'blank administrator' => [['admin_name' => '  ']],
            'uppercase host' => [['slug' => 'Acme']],
            'multiple host labels' => [['slug' => 'acme.example']],
            'leading hyphen' => [['slug' => '-acme']],
            'trailing hyphen' => [['slug' => 'acme-']],
            'oversized host label' => [['slug' => str_repeat('a', 64)]],
            'invalid email' => [['admin_email' => 'not-an-email']],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short']],
            'mismatched confirmation' => [['password_confirmation' => 'Something else']],
            'multibyte bcrypt limit' => [['password' => str_repeat('é', 40), 'password_confirmation' => str_repeat('é', 40)]],
        ];
    }

    public function test_missing_migration_identity_never_falls_back_to_runtime_credentials(): void
    {
        config(['database.connections.pgsql_migrator.username' => null]);
        DB::shouldReceive('connection')->never();
        $this->expectException(\RuntimeException::class);

        app(ProvisionTenant::class)->execute([
            'name' => 'Acme', 'slug' => 'acme', 'admin_name' => 'Alex',
            'admin_email' => 'admin@example.com', 'password' => 'A secret password',
            'password_confirmation' => 'A secret password',
        ]);
    }
}
