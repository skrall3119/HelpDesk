<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiPostgresTest;
use Tests\Support\TicketFixture;

class ApiTenantReadsPostgresTest extends ApiPostgresTest
{
    use TicketFixture;

    public function test_every_available_resource_read_hides_other_tenants_ids(): void
    {
        $ticket = $this->ticketFixture();
        $comment = DB::table('ticket_comments')->insertGetId(['tenant_id' => $this->pair['a']['tenant_id'], 'ticket_id' => $ticket->id, 'user_id' => $this->pair['a']['user_id'], 'body' => 'Tenant A private text']);
        $token = $this->postJson($this->url('login', 'b'), ['email' => 'shared@example.test', 'password' => 'Synthetic fixture password only'])->assertOk()->json('access_token');
        $headers = ['Authorization' => 'Bearer '.$token];
        $substitutions = ['{user}' => $this->pair['a']['user_id'], '{client}' => (string) $ticket->client_id, '{contact}' => (string) $ticket->contact_id, '{ticket}' => (string) $ticket->id, '{comment}' => (string) $comment];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $path = substr($route->uri(), strlen('api/v1/'));
            if (str_contains($path, '{')) {
                $this->getJson($this->url(strtr($path, $substitutions), 'b'), $headers)->assertNotFound();
            } elseif (in_array($path, ['clients', 'tickets', 'deleted-tickets'])) {
                $this->getJson($this->url($path, 'b'), $headers)->assertOk()->assertJsonPath('meta.total', 0);
            } elseif (in_array($path, ['admin/users', 'assignees'])) {
                $this->getJson($this->url($path, 'b'), $headers)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->pair['b']['user_id']);
            } elseif ($path === 'admin/settings') {
                $this->getJson($this->url($path, 'b'), $headers)->assertOk()->assertJsonPath('data.id', $this->pair['b']['tenant_id']);
            }
        }
        $this->getJson($this->url('me', 'b'), $headers)->assertOk()->assertJsonPath('user.id', $this->pair['b']['user_id']);
    }
}
