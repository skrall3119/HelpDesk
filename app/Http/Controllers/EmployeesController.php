<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeesController extends EndpointController
{
    private const FIELDS = ['id', 'name', 'email', 'role', 'active', 'created_at', 'updated_at', 'deleted_at'];

    public function index(Request $request)
    {
        $this->authorize($request, 'users.manage');
        $data = $this->input($request, ['page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer'], 'deleted' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'], 'q' => ['sometimes', 'string', 'max:255']]);
        $query = $this->table($request, 'users');
        $request->boolean('deleted') ? $query->whereNotNull('deleted_at') : $query->whereNull('deleted_at');
        if (isset($data['active'])) {
            $query->where('active', $request->boolean('active'));
        }
        if (isset($data['q'])) {
            $query->where(fn ($q) => $q->whereRaw('strpos(lower(name), lower(?)) > 0', [$data['q']])->orWhereRaw('strpos(lower(email), lower(?)) > 0', [$data['q']]));
        }

        return $this->collection($request, $query->orderBy('name')->orderBy('id'), self::FIELDS);
    }

    public function show(Request $request, string $user)
    {
        $this->authorize($request, 'users.manage');
        $this->input($request, ['deleted' => ['sometimes', 'boolean']]);

        return $this->result($this->record($request, 'users', $user, deleted: $request->boolean('deleted')));
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'users.manage');
        $data = $this->input($request, [...$this->rules(), 'password' => ['required', 'string', 'min:8', function ($field, $value, $fail) {
            if (strlen($value) > 72) {
                $fail('Password must not exceed 72 bytes.');
            }
        }]]);
        $this->lockTenant($request);
        $this->uniqueEmail($request, $data['email']);
        $id = $this->table($request, 'users')->insertGetId(['tenant_id' => $request->attributes->get('tenant_id'), 'name' => $data['name'], 'email' => strtolower($data['email']), 'role' => $data['role'], 'password' => Hash::make($data['password'])]);

        return $this->result($this->record($request, 'users', (string) $id), 201);
    }

    public function update(Request $request, string $user)
    {
        $this->authorize($request, 'users.manage');
        $data = $this->input($request, [...$this->rules(true), 'updated_at' => ['required', 'date']]);
        $this->lockTenant($request);
        $row = $this->record($request, 'users', $user, true);
        $this->version($request, $row);
        if (isset($data['email'])) {
            $this->uniqueEmail($request, $data['email'], $user);
            $data['email'] = strtolower($data['email']);
        }
        if (isset($data['role']) && $data['role'] !== 'administrator') {
            $this->protectAdministrator($request, $row);
        }
        unset($data['updated_at']);
        if ($data) {
            $this->table($request, 'users')->where('id', $user)->update($data);
        }

        return $this->result($this->record($request, 'users', $user));
    }

    public function lifecycle(Request $request, string $user)
    {
        $this->authorize($request, 'users.manage');
        $this->input($request, ['updated_at' => ['required', 'date']]);
        $this->lockTenant($request);
        $action = $request->route()->defaults['lifecycle'];
        $row = $this->record($request, 'users', $user, true, $action === 'restore');
        $this->version($request, $row);
        if (in_array($action, ['deactivate', 'delete'], true)) {
            $this->protectAdministrator($request, $row);
        }
        $changes = match ($action) {
            'activate' => ['active' => true],
            'deactivate' => ['active' => false],
            'delete' => ['active' => false, 'deleted_at' => now()],
            'restore' => ['active' => false, 'deleted_at' => null],
        };
        $this->table($request, 'users')->where('id', $user)->update($changes);
        if ($action !== 'activate') {
            $this->table($request, 'access_tokens')->where('user_id', $user)->delete();
        }
        if ($action === 'delete') {
            return response()->noContent();
        }

        return $this->result($this->record($request, 'users', $user));
    }

    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return ['name' => [$presence, 'required', 'string', 'max:255'], 'email' => [$presence, 'required', 'email:rfc', 'max:254'], 'role' => [$presence, 'required', Rule::in(['administrator', 'manager', 'technician'])]];
    }

    private function lockTenant(Request $request): void
    {
        DB::table('tenants')->where('id', $request->attributes->get('tenant_id'))->lockForUpdate()->first();
    }

    private function uniqueEmail(Request $request, string $email, ?string $except = null): void
    {
        $query = $this->table($request, 'users')->whereRaw('lower(email) = ?', [strtolower($email)]);
        if ($except) {
            $query->where('id', '<>', $except);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['email' => 'Email is already reserved in this organization.']);
        }
    }

    private function protectAdministrator(Request $request, object $row): void
    {
        if ($row->role === 'administrator' && $row->active && $this->table($request, 'users')->where('role', 'administrator')->where('active', true)->whereNull('deleted_at')->count() <= 1) {
            abort(409, 'The last active administrator must be retained.');
        }
    }

    private function result(object $row, int $status = 200)
    {
        return response()->json(['data' => $this->resource($row, self::FIELDS)], $status);
    }
}
