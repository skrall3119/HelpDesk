<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiPostgresTest;
use Tests\Support\TicketFixture;

class CommentsPostgresTest extends ApiPostgresTest
{
    use TicketFixture;

    public function test_edits_retain_revisions_and_deletion_preserves_body_with_actor(): void
    {
        $ticket = $this->ticketFixture();
        $base = 'tickets/'.$ticket->id.'/comments';
        $comment = $this->postJson($this->url($base), ['body' => '<script>Plain text only</script>'], $this->headers)->assertCreated()->json('data');
        $path = $base.'/'.$comment['id'];
        $changed = $this->patchJson($this->url($path), ['body' => 'Revised', 'updated_at' => $comment['updated_at']], $this->headers)->assertOk()->json('data');
        $this->patchJson($this->url($path), ['body' => 'Stale', 'updated_at' => $comment['updated_at']], $this->headers)->assertConflict();
        $this->getJson($this->url($path.'/revisions'), $this->headers)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.body', '<script>Plain text only</script>');
        $this->patchJson($this->url($path), ['body' => 'Revised', 'updated_at' => $changed['updated_at']], $this->headers)->assertOk();
        $this->assertSame(1, DB::table('comment_revisions')->where('comment_id', $comment['id'])->count());
        $this->deleteJson($this->url($path), ['updated_at' => $changed['updated_at']], $this->headers)->assertNoContent();
        $this->getJson($this->url($path), $this->headers)->assertNotFound();
        $this->getJson($this->url($path.'?deleted=1'), $this->headers)->assertOk()->assertJsonPath('data.body', 'Revised')->assertJsonPath('data.deleted_by_user_id', $this->pair['a']['user_id']);
        $this->role('technician');
        $this->getJson($this->url($base.'?deleted=1'), $this->headers)->assertForbidden();
    }

    public function test_ownership_parent_matching_and_deleted_ticket_write_protection(): void
    {
        $ticket = $this->ticketFixture();
        $other = $this->ticketFixture();
        $base = 'tickets/'.$ticket->id.'/comments';
        $this->postJson($this->url($base), ['body' => ' '], $this->headers)->assertUnprocessable();
        $this->postJson($this->url($base), ['body' => 'Text', 'user_id' => $this->pair['b']['user_id']], $this->headers)->assertUnprocessable();
        $author = DB::table('users')->insertGetId(['tenant_id' => $this->pair['a']['tenant_id'], 'name' => 'Other author', 'email' => 'other@example.test', 'password' => 'synthetic-hash', 'role' => 'technician']);
        $id = DB::table('ticket_comments')->insertGetId(['tenant_id' => $this->pair['a']['tenant_id'], 'ticket_id' => $ticket->id, 'user_id' => $author, 'body' => 'Other author body']);
        $comment = DB::table('ticket_comments')->where('id', $id)->first();
        $this->role('manager');
        $this->patchJson($this->url($base.'/'.$id), ['body' => 'Forged edit', 'updated_at' => $comment->updated_at], $this->headers)->assertForbidden();
        $this->getJson($this->url('tickets/'.$other->id.'/comments/'.$id), $this->headers)->assertNotFound();
        $this->role('administrator');
        $this->patchJson($this->url($base.'/'.$id), ['body' => 'Admin correction', 'updated_at' => $comment->updated_at], $this->headers)->assertOk();
        DB::table('tickets')->where('id', $ticket->id)->update(['deleted_at' => now()]);
        $this->postJson($this->url($base), ['body' => 'Hidden mutation'], $this->headers)->assertNotFound();
        $this->getJson($this->url($base.'?deleted_ticket=1'), $this->headers)->assertOk();
        $this->role('technician');
        $this->getJson($this->url($base.'?deleted_ticket=1'), $this->headers)->assertForbidden();
    }
}
