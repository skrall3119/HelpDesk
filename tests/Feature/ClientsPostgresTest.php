<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiPostgresTest;

class ClientsPostgresTest extends ApiPostgresTest
{
    public function test_atomic_creation_validation_and_tenant_isolation(): void
    {
        $body = ['name' => 'Business', 'client_type' => 'business', 'primary_contact' => ['name' => 'Primary', 'phone' => '+1 555 0100']];
        $client = $this->postJson($this->url('clients'), $body, $this->headers)->assertCreated()->json('data');
        $this->assertSame(1, DB::table('contacts')->where('client_id', $client['id'])->where('is_primary', true)->count());
        $this->postJson($this->url('clients'), [...$body, 'primary_contact' => ['name' => 'No method']], $this->headers)->assertUnprocessable();
        $this->postJson($this->url('clients'), [...$body, 'country' => 'ZZ'], $this->headers)->assertUnprocessable();
        $this->postJson($this->url('clients'), [...$body, 'tenant_id' => '99'], $this->headers)->assertUnprocessable();
        $this->getJson($this->url('clients'), $this->headers)->assertJsonPath('meta.total', 1);
        $this->getJson($this->url('clients?per_page=101'), $this->headers)->assertUnprocessable();
        $this->getJson($this->url('clients/'.$client['id'], 'b'), $this->headers)->assertUnauthorized();
        $this->patchJson($this->url('clients/'.$client['id']), ['name' => 'Revised', 'updated_at' => $client['updated_at']], $this->headers)->assertOk();
        $this->patchJson($this->url('clients/'.$client['id']), ['name' => 'Stale', 'updated_at' => $client['updated_at']], $this->headers)->assertConflict();
    }

    public function test_technicians_edit_but_cannot_deactivate_and_managers_restore(): void
    {
        $this->role('technician');
        $client = $this->postJson($this->url('clients'), ['name' => 'Individual', 'client_type' => 'individual', 'primary_contact' => ['name' => 'Person', 'email' => 'person@example.test']], $this->headers)->assertCreated()->json('data');
        $this->postJson($this->url('clients/'.$client['id'].'/deactivate'), ['updated_at' => $client['updated_at']], $this->headers)->assertForbidden();
        $this->role('manager');
        $client = $this->postJson($this->url('clients/'.$client['id'].'/deactivate'), ['updated_at' => $client['updated_at']], $this->headers)->assertOk()->assertJsonPath('data.active', false)->json('data');
        $this->getJson($this->url('clients?active=1'), $this->headers)->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('clients/'.$client['id']), $this->headers)->assertOk();
        $this->postJson($this->url('clients/'.$client['id'].'/restore'), ['updated_at' => $client['updated_at']], $this->headers)->assertOk()->assertJsonPath('data.active', true);
    }
}
