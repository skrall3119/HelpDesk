<?php

namespace Tests\Feature;

use App\Auth\BearerTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ApiPostgresTest;
use Tests\Support\ConcurrentRequests;

class EmployeesPostgresTest extends ApiPostgresTest
{
    use ConcurrentRequests;

    public function test_concurrent_administrator_demotions_leave_one_active_administrator(): void
    {
        $this->role('administrator');
        $id = DB::table('users')->insertGetId(['tenant_id' => $this->pair['a']['tenant_id'], 'name' => 'Second admin', 'email' => 'second@example.test', 'password' => Hash::make('Synthetic password'), 'role' => 'administrator']);
        $second = app(BearerTokens::class)->issue(User::findOrFail($id));
        $requests = [];
        foreach ([$this->pair['a']['user_id'] => $this->headers['Authorization'], $id => 'Bearer '.$second['access_token']] as $user => $authorization) {
            $version = DB::table('users')->where('id', $user)->value('updated_at');
            $requests[] = ['url' => $this->url('admin/users/'.$user), 'method' => 'PATCH', 'authorization' => $authorization, 'body' => ['role' => 'technician', 'updated_at' => $version]];
        }
        DB::commit();
        $responses = $this->concurrentRequests($requests);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        DB::beginTransaction();
        DB::selectOne("SELECT set_config('app.tenant_id', ?, true)", [$this->pair['a']['tenant_id']]);
        $this->assertSame(1, DB::table('users')->where('role', 'administrator')->where('active', true)->count());
    }

    public function test_employee_creation_validation_role_restrictions_and_safe_output(): void
    {
        $body = ['name' => 'Employee', 'email' => 'employee@example.test', 'role' => 'technician', 'password' => '12345678'];
        $employee = $this->postJson($this->url('admin/users'), $body, $this->headers)->assertCreated()->assertJsonMissingPath('data.password')->json('data');
        $this->postJson($this->url('admin/users'), [...$body, 'email' => 'EMPLOYEE@example.test'], $this->headers)->assertUnprocessable();
        $this->postJson($this->url('admin/users'), [...$body, 'email' => 'new@example.test', 'password' => '1234567'], $this->headers)->assertUnprocessable();
        $this->postJson($this->url('admin/users'), [...$body, 'tenant_id' => $this->pair['b']['tenant_id']], $this->headers)->assertUnprocessable();
        $this->getJson($this->url('admin/users/'.$this->pair['b']['user_id']), $this->headers)->assertNotFound();
        $this->patchJson($this->url('admin/users/'.$employee['id']), ['name' => 'Changed', 'updated_at' => $employee['updated_at']], $this->headers)->assertOk();
        $this->patchJson($this->url('admin/users/'.$employee['id']), ['name' => 'Stale', 'updated_at' => $employee['updated_at']], $this->headers)->assertConflict();
        $this->getJson($this->url('admin/users?per_page=101'), $this->headers)->assertUnprocessable();
        foreach (['manager', 'technician'] as $role) {
            $this->role($role);
            $this->getJson($this->url('admin/users'), $this->headers)->assertForbidden();
            $this->postJson($this->url('admin/users'), $body, $this->headers)->assertForbidden();
        }
    }

    public function test_last_administrator_is_protected_and_deactivation_permanently_revokes_tokens(): void
    {
        $admin = $this->getJson($this->url('admin/users/'.$this->pair['a']['user_id']), $this->headers)->json('data');
        foreach (['deactivate', ''] as $action) {
            $url = $this->url('admin/users/'.$admin['id'].($action ? '/'.$action : ''));
            ($action ? $this->postJson($url, ['updated_at' => $admin['updated_at']], $this->headers) : $this->deleteJson($url, ['updated_at' => $admin['updated_at']], $this->headers))->assertConflict();
        }
        $this->patchJson($this->url('admin/users/'.$admin['id']), ['role' => 'manager', 'updated_at' => $admin['updated_at']], $this->headers)->assertConflict();
        $employee = $this->postJson($this->url('admin/users'), ['name' => 'Employee', 'email' => 'employee@example.test', 'role' => 'technician', 'password' => '12345678'], $this->headers)->assertCreated()->json('data');
        $token = $this->postJson($this->url('login'), ['email' => $employee['email'], 'password' => '12345678'])->assertOk()->json('access_token');
        $employee = $this->postJson($this->url('admin/users/'.$employee['id'].'/deactivate'), ['updated_at' => $employee['updated_at']], $this->headers)->assertOk()->assertJsonPath('data.active', false)->json('data');
        $this->postJson($this->url('admin/users/'.$employee['id'].'/activate'), ['updated_at' => $employee['updated_at']], $this->headers)->assertOk();
        $this->getJson($this->url('me'), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $employee = $this->getJson($this->url('admin/users/'.$employee['id']), $this->headers)->json('data');
        $this->deleteJson($this->url('admin/users/'.$employee['id']), ['updated_at' => $employee['updated_at']], $this->headers)->assertNoContent();
        $this->getJson($this->url('admin/users/'.$employee['id']), $this->headers)->assertNotFound();
        $employee = $this->getJson($this->url('admin/users/'.$employee['id'].'?deleted=1'), $this->headers)->assertOk()->json('data');
        $this->postJson($this->url('admin/users/'.$employee['id'].'/restore'), ['updated_at' => $employee['updated_at']], $this->headers)->assertOk()->assertJsonPath('data.active', false);
    }
}
