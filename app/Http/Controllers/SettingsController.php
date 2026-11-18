<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends EndpointController
{
    public function show(Request $request)
    {
        $this->authorize($request, 'settings.manage');
        $this->input($request, []);

        return response()->json(['data' => $this->resource($this->tenant($request), ['id', 'name', 'updated_at'])]);
    }

    public function update(Request $request)
    {
        $this->authorize($request, 'settings.manage');
        $data = $this->input($request, ['name' => ['required', 'string', 'max:255'], 'updated_at' => ['required', 'date']]);
        $tenant = $this->tenant($request, true);
        $this->version($request, $tenant);
        DB::table('tenants')->where('id', $tenant->id)->update(['name' => $data['name']]);

        return response()->json(['data' => $this->resource($this->tenant($request), ['id', 'name', 'updated_at'])]);
    }

    private function tenant(Request $request, bool $lock = false): object
    {
        $query = DB::table('tenants')->where('id', $request->attributes->get('tenant_id'));

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? abort(404);
    }
}
