<?php

namespace Tests\Feature;

use Tests\Support\ApiPostgresTest;

class CapabilitiesPostgresTest extends ApiPostgresTest
{
    public function test_capabilities_follow_current_role_and_require_tenant_authentication(): void
    {
        $this->getJson($this->url('capabilities'))->assertUnauthorized();
        $this->getJson($this->url('capabilities', 'b'), $this->headers)->assertUnauthorized();
        $this->getJson($this->url('capabilities'), $this->headers)->assertOk()->assertJsonFragment(['users.manage']);
        $this->role('technician');
        $response = $this->getJson($this->url('capabilities'), $this->headers)->assertOk();
        $this->assertContains('tickets.claim', $response->json('data'));
        $this->assertNotContains('tickets.assign', $response->json('data'));
        $this->assertNotContains('users.manage', $response->json('data'));
        $this->role('manager');
        $response = $this->getJson($this->url('capabilities'), $this->headers)->assertOk();
        $this->assertContains('tickets.assign', $response->json('data'));
        $this->assertNotContains('settings.manage', $response->json('data'));
    }
}
