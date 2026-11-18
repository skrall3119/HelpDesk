<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiPostgresTest;
use Tests\Support\TicketFixture;

class HistoryPostgresTest extends ApiPostgresTest
{
    use TicketFixture;

    public function test_delete_restore_preserve_status_assignment_comments_and_audited_snapshots(): void
    {
        $ticket = $this->ticketFixture();
        DB::table('tickets')->where('id', $ticket->id)->update(['assigned_user_id' => $this->pair['a']['user_id']]);
        $ticket = DB::table('tickets')->where('id', $ticket->id)->first();
        DB::table('ticket_comments')->insert(['tenant_id' => $this->pair['a']['tenant_id'], 'ticket_id' => $ticket->id, 'user_id' => $this->pair['a']['user_id'], 'body' => 'Retained']);
        $this->deleteJson($this->url('tickets/'.$ticket->id), ['updated_at' => $ticket->updated_at], $this->headers)->assertNoContent();
        $this->getJson($this->url('deleted-tickets'), $this->headers)->assertOk()->assertJsonPath('meta.total', 1);
        $row = $this->getJson($this->url('deleted-tickets/'.$ticket->id), $this->headers)->assertOk()->json('data');
        $this->getJson($this->url('tickets/'.$ticket->id.'/activity'), $this->headers)->assertNotFound();
        $history = $this->getJson($this->url('tickets/'.$ticket->id.'/activity?deleted_ticket=1'), $this->headers)->assertOk()->json('data');
        $this->assertCount(3, $history);
        $this->assertIsString($history[0]['new_values']['ticket_number']);
        $this->assertSame($this->pair['a']['user_id'], $history[0]['new_values']['created_by_user_id']);
        $this->postJson($this->url('tickets/'.$ticket->id.'/restore'), ['updated_at' => $ticket->updated_at], $this->headers)->assertConflict();
        $this->postJson($this->url('tickets/'.$ticket->id.'/restore'), ['updated_at' => $row['updated_at']], $this->headers)->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.assigned_user_id', $this->pair['a']['user_id'])->assertJsonPath('data.deleted_at', null);
        $this->assertSame(1, DB::table('ticket_comments')->where('ticket_id', $ticket->id)->count());
        $this->getJson($this->url('tickets/'.$ticket->id.'/activity'), $this->headers)->assertJsonPath('meta.total', 4);
    }

    public function test_technicians_cannot_read_deleted_records_or_delete_restore(): void
    {
        $ticket = $this->ticketFixture();
        $this->role('technician');
        $this->getJson($this->url('tickets/'.$ticket->id.'/activity'), $this->headers)->assertOk();
        $this->getJson($this->url('deleted-tickets'), $this->headers)->assertForbidden();
        $this->getJson($this->url('deleted-tickets/'.$ticket->id), $this->headers)->assertForbidden();
        $this->getJson($this->url('tickets/'.$ticket->id.'/activity?deleted_ticket=1'), $this->headers)->assertForbidden();
        $this->deleteJson($this->url('tickets/'.$ticket->id), ['updated_at' => $ticket->updated_at], $this->headers)->assertForbidden();
        $this->postJson($this->url('tickets/'.$ticket->id.'/restore'), ['updated_at' => $ticket->updated_at], $this->headers)->assertForbidden();
    }
}
