<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiPostgresTest;
use Tests\Support\TicketFixture;

class TicketStatusPostgresTest extends ApiPostgresTest
{
    use TicketFixture;

    public function test_all_transitions_and_same_status_noop_match_the_confirmed_matrix(): void
    {
        $allowed = ['open' => ['in_progress', 'waiting_on_client', 'on_hold', 'closed'], 'in_progress' => ['waiting_on_client', 'on_hold', 'closed'], 'waiting_on_client' => ['in_progress', 'on_hold', 'closed'], 'on_hold' => ['in_progress', 'waiting_on_client', 'closed'], 'closed' => ['in_progress']];
        foreach ($allowed as $from => $next) {
            foreach (array_keys($allowed) as $to) {
                $ticket = $this->ticketFixture();
                DB::selectOne("SELECT set_config('app.status_reason', 'Fixture transition', true)");
                DB::table('tickets')->where('id', $ticket->id)->update(['assigned_user_id' => $this->pair['a']['user_id'], 'status' => $from]);
                $row = DB::table('tickets')->where('id', $ticket->id)->first();
                $count = DB::table('ticket_activities')->where('ticket_id', $ticket->id)->count();
                $this->role('technician');
                $response = $this->postJson($this->url('tickets/'.$ticket->id.'/status'), ['status' => $to, 'reason' => 'Synthetic reason', 'updated_at' => $row->updated_at], $this->headers);
                if ($from === $to || in_array($to, $next, true)) {
                    $response->assertOk()->assertJsonPath('data.status', $to)->assertJsonPath('data.assigned_user_id', $this->pair['a']['user_id']);
                    $this->assertSame($to === 'closed', $response->json('data.closed_at') !== null);
                } else {
                    $response->assertUnprocessable();
                }
                $this->assertSame($count + ($from !== $to && in_array($to, $next, true) ? 1 : 0), DB::table('ticket_activities')->where('ticket_id', $ticket->id)->count());
            }
        }
    }

    public function test_closure_requires_assignment_and_reasons_and_stale_writes_fail(): void
    {
        $ticket = $this->ticketFixture();
        $url = $this->url('tickets/'.$ticket->id.'/status');
        $this->postJson($url, ['status' => 'closed', 'reason' => 'Fixed', 'updated_at' => $ticket->updated_at], $this->headers)->assertUnprocessable();
        $this->postJson($url, ['status' => 'on_hold', 'updated_at' => $ticket->updated_at], $this->headers)->assertUnprocessable();
        $this->postJson($url, ['status' => 'on_hold', 'reason' => 'Awaiting parts', 'updated_at' => $ticket->updated_at], $this->headers)->assertOk();
        $this->postJson($url, ['status' => 'in_progress', 'updated_at' => $ticket->updated_at], $this->headers)->assertConflict();
        $this->postJson($url, ['status' => 'closed', 'assigned_user_id' => $this->pair['a']['user_id'], 'updated_at' => $ticket->updated_at], $this->headers)->assertUnprocessable();
    }
}
