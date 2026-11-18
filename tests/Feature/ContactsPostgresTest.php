<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiPostgresTest;

class ContactsPostgresTest extends ApiPostgresTest
{
    public function test_contacts_validate_merged_method_and_cannot_move_between_clients(): void
    {
        $client = $this->client();
        $base = 'clients/'.$client->id.'/contacts';
        $contact = $this->postJson($this->url($base), ['name' => 'Contact', 'phone' => '555-0100'], $this->headers)->assertCreated()->json('data');
        $this->postJson($this->url($base), ['name' => 'Missing method'], $this->headers)->assertUnprocessable();
        $this->patchJson($this->url($base.'/'.$contact['id']), ['phone' => null, 'updated_at' => $contact['updated_at']], $this->headers)->assertUnprocessable();
        $this->patchJson($this->url($base.'/'.$contact['id']), ['client_id' => '999', 'updated_at' => $contact['updated_at']], $this->headers)->assertUnprocessable();
        $contact = $this->patchJson($this->url($base.'/'.$contact['id']), ['email' => 'new@example.test', 'phone' => null, 'updated_at' => $contact['updated_at']], $this->headers)->assertOk()->json('data');
        $other = $this->client();
        $this->getJson($this->url('clients/'.$other->id.'/contacts/'.$contact['id']), $this->headers)->assertNotFound();
        $this->getJson($this->url($base.'?per_page=101'), $this->headers)->assertUnprocessable();
    }

    public function test_primary_switch_and_lifecycle_preserve_exactly_one_primary(): void
    {
        $client = $this->client();
        $base = 'clients/'.$client->id.'/contacts';
        $first = $this->postJson($this->url($base), ['name' => 'First', 'phone' => '555-0100'], $this->headers)->json('data');
        $second = $this->postJson($this->url($base), ['name' => 'Second', 'phone' => '555-0101'], $this->headers)->json('data');
        $first = $this->postJson($this->url($base.'/'.$first['id'].'/primary'), ['updated_at' => $first['updated_at'], 'client_updated_at' => $client->updated_at], $this->headers)->assertOk()->json('data');
        $this->postJson($this->url($base.'/'.$first['id'].'/deactivate'), ['updated_at' => $first['updated_at']], $this->headers)->assertConflict();
        $this->postJson($this->url($base.'/'.$second['id'].'/primary'), ['updated_at' => $second['updated_at'], 'client_updated_at' => $client->updated_at], $this->headers)->assertConflict();
        $version = DB::table('clients')->where('id', $client->id)->value('updated_at');
        $this->postJson($this->url($base.'/'.$second['id'].'/primary'), ['updated_at' => $second['updated_at'], 'client_updated_at' => $version], $this->headers)->assertOk();
        $this->assertSame(1, DB::table('contacts')->where('client_id', $client->id)->where('is_primary', true)->count());
        $first = $this->getJson($this->url($base.'/'.$first['id']), $this->headers)->json('data');
        $this->role('technician');
        $this->postJson($this->url($base.'/'.$first['id'].'/deactivate'), ['updated_at' => $first['updated_at']], $this->headers)->assertForbidden();
        $this->role('manager');
        $first = $this->postJson($this->url($base.'/'.$first['id'].'/deactivate'), ['updated_at' => $first['updated_at']], $this->headers)->assertOk()->json('data');
        $this->postJson($this->url($base.'/'.$first['id'].'/restore'), ['updated_at' => $first['updated_at']], $this->headers)->assertOk()->assertJsonPath('data.active', true);
    }

    private function client(): object
    {
        $this->role('administrator');
        $id = DB::table('clients')->insertGetId(['tenant_id' => $this->pair['a']['tenant_id'], 'name' => 'Client', 'client_type' => 'business']);

        return DB::table('clients')->where('id', $id)->first();
    }
}
