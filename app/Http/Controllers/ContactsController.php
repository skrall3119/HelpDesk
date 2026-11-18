<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactsController extends EndpointController
{
    private const FIELDS = ['id', 'client_id', 'name', 'email', 'phone', 'job_title', 'is_primary', 'active', 'created_at', 'updated_at'];

    public function index(Request $request, string $client)
    {
        $this->authorize($request, 'tickets.read');
        $this->record($request, 'clients', $client);
        $data = $this->input($request, ['page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer'], 'active' => ['sometimes', 'boolean'], 'q' => ['sometimes', 'string', 'max:255']]);
        $query = $this->table($request, 'contacts')->where('client_id', $client)->whereNull('deleted_at');
        if (isset($data['active'])) {
            $query->where('active', $request->boolean('active'));
        }
        if (isset($data['q'])) {
            $query->whereRaw('strpos(lower(name), lower(?)) > 0', [$data['q']]);
        }

        return $this->collection($request, $query->orderByDesc('is_primary')->orderBy('name')->orderBy('id'), self::FIELDS);
    }

    public function show(Request $request, string $client, string $contact)
    {
        $this->authorize($request, 'tickets.read');
        $this->input($request, []);
        $this->record($request, 'clients', $client);

        return $this->result($this->contact($request, $client, $contact));
    }

    public function store(Request $request, string $client)
    {
        $this->authorize($request, 'clients.write');
        $data = $this->input($request, $this->rules());
        $this->method($data);
        $this->record($request, 'clients', $client, true);
        $id = $this->table($request, 'contacts')->insertGetId(['tenant_id' => $request->attributes->get('tenant_id'), 'client_id' => $client, ...$data]);

        return $this->result($this->contact($request, $client, (string) $id), 201);
    }

    public function update(Request $request, string $client, string $contact)
    {
        $this->authorize($request, 'clients.write');
        $data = $this->input($request, [...$this->rules(true), 'updated_at' => ['required', 'date']]);
        $this->record($request, 'clients', $client, true);
        $row = $this->contact($request, $client, $contact, true);
        $this->version($request, $row);
        $this->method([...get_object_vars($row), ...$data]);
        unset($data['updated_at']);
        if ($data) {
            $this->table($request, 'contacts')->where('id', $contact)->update($data);
        }

        return $this->result($this->contact($request, $client, $contact));
    }

    public function primary(Request $request, string $client, string $contact)
    {
        $this->authorize($request, 'clients.write');
        $this->input($request, ['updated_at' => ['required', 'date'], 'client_updated_at' => ['required', 'date']]);
        $parent = $this->record($request, 'clients', $client, true);
        abort_unless(CarbonImmutable::parse($parent->updated_at)->equalTo(CarbonImmutable::parse($request->input('client_updated_at'))), 409, 'Client changed. Reload before retrying.');
        $row = $this->contact($request, $client, $contact, true);
        $this->version($request, $row);
        abort_unless($row->active, 409, 'Primary contact must be active.');
        $this->method(get_object_vars($row));
        $this->table($request, 'contacts')->where('client_id', $client)->where('is_primary', true)->where('id', '<>', $contact)->update(['is_primary' => false]);
        $this->table($request, 'contacts')->where('id', $contact)->update(['is_primary' => true]);
        $this->table($request, 'clients')->where('id', $client)->update(['updated_at' => now()]);

        return $this->result($this->contact($request, $client, $contact));
    }

    public function lifecycle(Request $request, string $client, string $contact)
    {
        $this->authorize($request, 'clients.lifecycle');
        $this->input($request, ['updated_at' => ['required', 'date']]);
        $this->record($request, 'clients', $client, true);
        $row = $this->contact($request, $client, $contact, true);
        $this->version($request, $row);
        $active = $request->route()->defaults['active'];
        if (! $active) {
            abort_if($row->is_primary, 409, 'Replace the primary contact before deactivation.');
        }
        if ($active && $row->is_primary) {
            abort_if($this->table($request, 'contacts')->where('client_id', $client)->where('id', '<>', $contact)->where('is_primary', true)->where('active', true)->whereNull('deleted_at')->exists(), 409, 'Another active primary contact exists.');
        }
        $this->method(get_object_vars($row));
        $this->table($request, 'contacts')->where('id', $contact)->update(['active' => $active]);

        return $this->result($this->contact($request, $client, $contact));
    }

    private function rules(bool $partial = false): array
    {
        return ['name' => [$partial ? 'sometimes' : 'required', 'required', 'string', 'max:255'], 'email' => ['nullable', 'email:rfc', 'max:254'], 'phone' => ['nullable', 'string', 'max:50'], 'job_title' => ['nullable', 'string', 'max:255']];
    }

    private function method(array $data): void
    {
        Validator::make($data, ['email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:254'], 'phone' => ['nullable', 'required_without:email', 'string', 'max:50']])->validate();
    }

    private function contact(Request $request, string $client, string $contact, bool $lock = false): object
    {
        $row = $this->record($request, 'contacts', $contact, $lock);
        abort_unless((string) $row->client_id === $client, 404);

        return $row;
    }

    private function result(object $row, int $status = 200)
    {
        return response()->json(['data' => $this->resource($row, self::FIELDS)], $status);
    }
}
