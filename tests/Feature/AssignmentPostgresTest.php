<?php

namespace Tests\Feature;

use App\Auth\BearerTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ApiPostgresTest;
use Tests\Support\ConcurrentRequests;
use Tests\Support\TicketFixture;

class AssignmentPostgresTest extends ApiPostgresTest
{
    use ConcurrentRequests;
    use TicketFixture;

    public function test_two_independent_http_processes_produce_one_claim_winner(): void
    {
        $ticket = $this->ticketFixture();
        $id = DB::table('users')->insertGetId(['tenant_id' => $this->pair['a']['tenant_id'], 'name' => 'Second claimant', 'email' => 'claimant@example.test', 'role' => 'technician', 'password' => Hash::make('Synthetic password')]);
        $second = app(BearerTokens::class)->issue(User::findOrFail($id));
        DB::commit();
        // Races require committed rows. Preserve append-only audit in this uniquely
        // named synthetic tenant; CI discards the entire _test database afterward.
        $this->fixtures = null;
        $request = ['url' => $this->url('tickets/'.$ticket->id.'/claim'), 'method' => 'POST', 'body' => []];
        $responses = $this->concurrentRequests([
            [...$request, 'authorization' => $this->headers['Authorization']],
            [...$request, 'authorization' => 'Bearer '.$second['access_token']],
        ]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $winner = array_values(array_filter($responses, fn ($r) => $r['status'] === 200))[0]['body']['data']['assigned_user_id'];
        $loser = array_values(array_filter($responses, fn ($r) => $r['status'] === 409))[0]['body']['assigned_user_id'];
        $this->assertSame($winner, $loser);
        DB::beginTransaction();
        DB::selectOne("SELECT set_config('app.tenant_id', ?, true)", [$this->pair['a']['tenant_id']]);
        $this->assertSame(2, DB::table('ticket_activities')->where('ticket_id', $ticket->id)->count());
    }

    public function test_claim_is_self_only_and_cannot_take_assigned_work(): void
    {
        $ticket = $this->ticketFixture();
        $this->role('technician');
        $this->postJson($this->url('tickets/'.$ticket->id.'/claim'), ['assigned_user_id' => $this->pair['b']['user_id']], $this->headers)->assertUnprocessable();
        $this->postJson($this->url('tickets/'.$ticket->id.'/claim'), [], $this->headers)->assertOk()->assertJsonPath('data.assigned_user_id', $this->pair['a']['user_id']);
        $this->postJson($this->url('tickets/'.$ticket->id.'/claim'), [], $this->headers)->assertConflict()->assertJsonPath('assigned_user_id', $this->pair['a']['user_id']);
        $this->postJson($this->url('tickets/'.$ticket->id.'/assignment'), ['assigned_user_id' => $this->pair['a']['user_id'], 'updated_at' => $ticket->updated_at], $this->headers)->assertForbidden();
        $this->postJson($this->url('tickets/'.$ticket->id.'/unassign'), ['updated_at' => $ticket->updated_at], $this->headers)->assertForbidden();
        $this->assertSame(2, DB::table('ticket_activities')->where('ticket_id', $ticket->id)->count());
    }

    public function test_manager_assignment_checks_tenant_and_closed_unassignment_reopens_atomically(): void
    {
        $ticket = $this->ticketFixture();
        $this->postJson($this->url('tickets/'.$ticket->id.'/assignment'), ['assigned_user_id' => $this->pair['b']['user_id'], 'updated_at' => $ticket->updated_at], $this->headers)->assertNotFound();
        $ticket = $this->postJson($this->url('tickets/'.$ticket->id.'/assignment'), ['assigned_user_id' => $this->pair['a']['user_id'], 'updated_at' => $ticket->updated_at], $this->headers)->assertOk()->json('data');
        DB::selectOne("SELECT set_config('app.status_reason', 'Fixed', true)");
        DB::table('tickets')->where('id', $ticket['id'])->update(['status' => 'closed']);
        $row = DB::table('tickets')->where('id', $ticket['id'])->first();
        $this->postJson($this->url('tickets/'.$row->id.'/unassign'), ['updated_at' => $row->updated_at], $this->headers)->assertUnprocessable();
        $this->postJson($this->url('tickets/'.$row->id.'/unassign'), ['updated_at' => $row->updated_at, 'reopen_reason' => 'Additional investigation'], $this->headers)->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.assigned_user_id', null)->assertJsonPath('data.closed_at', null);
        $this->postJson($this->url('tickets/'.$row->id.'/assignment'), ['assigned_user_id' => $this->pair['a']['user_id'], 'updated_at' => $row->updated_at], $this->headers)->assertConflict();
    }
}
