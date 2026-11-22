<?php

namespace Tests\Feature;

use Tests\Support\ApiPostgresTest;

class SettingsPostgresTest extends ApiPostgresTest
{
    public function test_settings_update_is_tenant_scoped_versioned_and_field_allowlisted(): void
    {
        $data = $this->getJson($this->url('admin/settings'), $this->headers)->assertOk()->json('data');
        $this->assertSame($this->pair['a']['tenant_id'], $data['id']);
        $this->patchJson($this->url('admin/settings'), ['name' => 'Revised name', 'updated_at' => $data['updated_at']], $this->headers)->assertOk()->assertJsonPath('data.name', 'Revised name');
        $this->patchJson($this->url('admin/settings'), ['name' => 'Stale', 'updated_at' => $data['updated_at']], $this->headers)->assertConflict();
        $this->patchJson($this->url('admin/settings'), ['name' => ' ', 'updated_at' => $data['updated_at']], $this->headers)->assertUnprocessable();
        $this->patchJson($this->url('admin/settings'), ['slug' => 'hijack', 'name' => 'X', 'updated_at' => $data['updated_at']], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->getJson($this->url('admin/settings', 'b'), $this->headers)->assertUnauthorized();
    }

    public function test_only_administrators_can_read_or_update_settings(): void
    {
        foreach (['manager', 'technician'] as $role) {
            $this->role($role);
            $this->getJson($this->url('admin/settings'), $this->headers)->assertForbidden();
            $this->patchJson($this->url('admin/settings'), ['name' => 'Unauthorized'], $this->headers)->assertForbidden();
        }
    }
}
