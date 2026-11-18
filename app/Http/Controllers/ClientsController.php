<?php

namespace App\Http\Controllers;

use App\Support\CountryCodes;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientsController extends EndpointController
{
    private const FIELDS = ['id', 'client_type', 'name', 'email', 'phone', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'active', 'created_at', 'updated_at'];

    public function index(Request $request)
    {
        $this->authorize($request, 'tickets.read');
        $data = $this->input($request, ['page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer'], 'q' => ['sometimes', 'string', 'max:255'], 'active' => ['sometimes', 'boolean'], 'client_type' => ['sometimes', Rule::in(['individual', 'business'])]]);
        $query = $this->table($request, 'clients')->whereNull('deleted_at');
        foreach (['active', 'client_type'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['q'])) {
            $query->whereRaw('strpos(lower(name), lower(?)) > 0', [$data['q']]);
        }

        return $this->collection($request, $query->orderBy('name')->orderBy('id'), self::FIELDS);
    }

    public function show(Request $request, string $client)
    {
        $this->authorize($request, 'tickets.read');
        $this->input($request, []);

        return $this->result($this->record($request, 'clients', $client));
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'clients.write');
        $data = $this->input($request, [...$this->rules(), 'primary_contact' => ['required', 'array:name,email,phone,job_title'], 'primary_contact.name' => ['required', 'string', 'max:255'], 'primary_contact.email' => ['nullable', 'required_without:primary_contact.phone', 'email:rfc', 'max:254'], 'primary_contact.phone' => ['nullable', 'required_without:primary_contact.email', 'string', 'max:50'], 'primary_contact.job_title' => ['nullable', 'string', 'max:255']]);
        $contact = $data['primary_contact'];
        unset($data['primary_contact']);
        $tenant = $request->attributes->get('tenant_id');
        $id = $this->table($request, 'clients')->insertGetId(['tenant_id' => $tenant, ...$data]);
        $this->table($request, 'contacts')->insert(['tenant_id' => $tenant, 'client_id' => $id, 'is_primary' => true, ...$contact]);

        return $this->result($this->record($request, 'clients', (string) $id), 201);
    }

    public function update(Request $request, string $client)
    {
        $this->authorize($request, 'clients.write');
        $data = $this->input($request, [...$this->rules(true), 'updated_at' => ['required', 'date']]);
        $row = $this->record($request, 'clients', $client, true);
        $this->version($request, $row);
        unset($data['updated_at']);
        if ($data) {
            $this->table($request, 'clients')->where('id', $client)->update($data);
        }

        return $this->result($this->record($request, 'clients', $client));
    }

    public function lifecycle(Request $request, string $client)
    {
        $this->authorize($request, 'clients.lifecycle');
        $this->input($request, ['updated_at' => ['required', 'date']]);
        $row = $this->record($request, 'clients', $client, true);
        $this->version($request, $row);
        $active = $request->route()->defaults['active'];
        if ($active) {
            abort_unless($this->table($request, 'contacts')->where('client_id', $client)->where('is_primary', true)->where('active', true)->whereNull('deleted_at')->count() === 1, 409, 'An active primary contact is required before restoration.');
        }
        $this->table($request, 'clients')->where('id', $client)->update(['active' => $active]);

        return $this->result($this->record($request, 'clients', $client));
    }

    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';
        $rules = ['name' => [$presence, 'required', 'string', 'max:255'], 'client_type' => [$presence, 'required', Rule::in(['individual', 'business'])], 'email' => ['nullable', 'email:rfc', 'max:254'], 'phone' => ['nullable', 'string', 'max:50'], 'postal_code' => ['nullable', 'string', 'max:32'], 'country' => ['nullable', 'string', Rule::in(CountryCodes::all())]];
        foreach (['address_line_1', 'address_line_2', 'city', 'state'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    private function result(object $row, int $status = 200)
    {
        return response()->json(['data' => $this->resource($row, self::FIELDS)], $status);
    }
}
