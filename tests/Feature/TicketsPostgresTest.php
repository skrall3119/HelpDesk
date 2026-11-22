<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiPostgresTest;

class TicketsPostgresTest extends ApiPostgresTest
{
    private function inputTicket(): array
    {
        $this->role('administrator');
        $tenant = $this->pair['a']['tenant_id'];
        $client = DB::table('clients')->insertGetId(['tenant_id' => $tenant, 'name' => 'Client', 'client_type' => 'business']);
        $contact = DB::table('contacts')->insertGetId(['tenant_id' => $tenant, 'client_id' => $client, 'name' => 'Contact', 'phone' => '555-0100', 'is_primary' => true]);

        return ['client_id' => (string) $client, 'contact_id' => (string) $contact, 'subject' => 'Network failure', 'description' => 'Router cannot connect'];
    }

    public function test_creation_edits_audit_filters_and_historical_contact_information(): void
    {
        $body = $this->inputTicket();
        $ticket = $this->postJson($this->url('tickets'), $body, $this->headers)->assertCreated()->assertJsonPath('data.status', 'open')->json('data');
        $this->assertIsString($ticket['ticket_number']);
        $this->assertSame(1, DB::table('ticket_activities')->where('ticket_id', $ticket['id'])->count());
        $this->getJson($this->url('tickets?q=CL-'.str_pad($ticket['ticket_number'], 6, '0', STR_PAD_LEFT)), $this->headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->url('tickets?q=router'), $this->headers)->assertJsonPath('meta.total', 1);
        $this->getJson($this->url('tickets?view=my'), $this->headers)->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('tickets?view=unassigned&priority=normal'), $this->headers)->assertJsonPath('meta.total', 1);
        $this->getJson($this->url('tickets?sort=bad'), $this->headers)->assertUnprocessable();
        $this->getJson($this->url('tickets?q=999999999999999999999999'), $this->headers)->assertUnprocessable();
        DB::table('contacts')->where('id', $body['contact_id'])->update(['phone' => '555-0200', 'active' => false]);
        $this->getJson($this->url('tickets/'.$ticket['id']), $this->headers)->assertJsonPath('data.contact.phone', '555-0200')->assertJsonPath('data.contact.active', false);
        $this->patchJson($this->url('tickets/'.$ticket['id']), ['description' => 'Corrected description', 'updated_at' => $ticket['updated_at']], $this->headers)->assertOk();
        $this->patchJson($this->url('tickets/'.$ticket['id']), ['subject' => 'Stale', 'updated_at' => $ticket['updated_at']], $this->headers)->assertConflict();
        $this->postJson($this->url('tickets'), $body, $this->headers)->assertUnprocessable();
        $this->assertSame(2, DB::table('ticket_activities')->where('ticket_id', $ticket['id'])->count());
    }

    public function test_generic_edits_cannot_bypass_assignment_status_or_identity_rules(): void
    {
        $body = $this->inputTicket();
        $other = $this->inputTicket();
        $this->postJson($this->url('tickets'), [...$body, 'contact_id' => $other['contact_id']], $this->headers)->assertUnprocessable();
        $ticket = $this->postJson($this->url('tickets'), $body, $this->headers)->assertCreated()->json('data');
        foreach (['status' => 'closed', 'assigned_user_id' => $this->pair['a']['user_id'], 'tenant_id' => $this->pair['b']['tenant_id'], 'closed_at' => now()->toISOString()] as $field => $value) {
            $this->patchJson($this->url('tickets/'.$ticket['id']), [$field => $value, 'updated_at' => $ticket['updated_at']], $this->headers)->assertUnprocessable();
        }
        $this->patchJson($this->url('tickets/'.$ticket['id']), ['client_id' => $other['client_id'], 'updated_at' => $ticket['updated_at']], $this->headers)->assertUnprocessable();
        $this->role('technician');
        $this->postJson($this->url('tickets'), [...$body, 'assigned_user_id' => $this->pair['b']['user_id']], $this->headers)->assertForbidden();
        $this->postJson($this->url('tickets'), [...$body, 'assigned_user_id' => $this->pair['a']['user_id']], $this->headers)->assertCreated();
        $this->getJson($this->url('tickets/999999999999999999999999'), $this->headers)->assertNotFound();
    }
}
