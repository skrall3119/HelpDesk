<?php

namespace App\Console\Commands;

use App\Actions\Tenants\ProvisionTenant as ProvisionTenantAction;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProvisionTenant extends Command
{
    protected $signature = 'caselantern:provision-tenant
        {--name= : Organization display name}
        {--slug= : Organization subdomain label}
        {--admin-name= : First administrator display name}
        {--admin-email= : First administrator email}';

    protected $description = 'Create a tenant and its first administrator atomically';

    public function handle(ProvisionTenantAction $provision): int
    {
        if ($this->option('no-interaction')) {
            $this->error('Run interactively: the administrator password must be entered at a hidden prompt.');

            return self::FAILURE;
        }

        try {
            $result = $provision->execute([
                'name' => $this->option('name') ?? $this->ask('Organization name'),
                'slug' => $this->option('slug') ?? $this->ask('Tenant subdomain slug'),
                'admin_name' => $this->option('admin-name') ?? $this->ask('Administrator name'),
                'admin_email' => $this->option('admin-email') ?? $this->ask('Administrator email'),
                // Disable visible fallback if this terminal cannot hide input.
                'password' => $this->secret('Administrator password (12+ characters, at most 72 bytes)', false),
                'password_confirmation' => $this->secret('Confirm administrator password', false),
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        } catch (QueryException $exception) {
            // Do not print SQL bindings, hashes, or connection details.
            $this->error((string) $exception->getCode() === '23505'
                ? 'That tenant slug already exists; no existing account was changed.'
                : 'Provisioning failed. Check migration credentials and the applied schema; the transaction was rolled back.');

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error('Provisioning could not complete. Check the dedicated migration connection and hidden-input support.');

            return self::FAILURE;
        }

        $this->info('Tenant '.$result['slug'].' created (tenant '.$result['tenant_id'].', administrator '.$result['user_id'].').');
        $this->line('The account is ready for the upcoming tenant login flow; browser authentication is not implemented yet.');

        return self::SUCCESS;
    }
}
