<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketsController extends EndpointController
{
    private const FIELDS = ['id', 'ticket_number', 'client_id', 'contact_id', 'assigned_user_id', 'created_by_user_id', 'subject', 'status', 'priority', 'created_at', 'updated_at', 'closed_at'];

    private const STATUSES = ['open', 'in_progress', 'waiting_on_client', 'on_hold', 'closed'];

    private const PRIORITIES = ['low', 'normal', 'high', 'critical'];

    public function index(Request $request)
    {
        $this->authorize($request, 'tickets.read');
        $data = $this->input($request, ['page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer'], 'view' => ['sometimes', Rule::in(['all', 'my', 'unassigned'])], 'status' => ['sometimes', Rule::in([...self::STATUSES, 'all'])], 'priority' => ['sometimes', Rule::in(self::PRIORITIES)], 'client_id' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'], 'assigned_user_id' => ['sometimes', 'integer', 'min:1', 'max:9223372036854775807'], 'q' => ['sometimes', 'string', 'max:255'], 'sort' => ['sometimes', Rule::in(['newest', 'oldest'])]]);
        $query = $this->table($request, 'tickets')->whereNull('deleted_at');
        if (! isset($data['status'])) {
            $query->whereNotIn('status', ['on_hold', 'closed']);
        } elseif ($data['status'] !== 'all') {
            $query->where('status', $data['status']);
        }
        foreach (['priority', 'client_id', 'assigned_user_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (($data['view'] ?? '') === 'my') {
            $query->where('assigned_user_id', $request->user('api')->id);
        }
        if (($data['view'] ?? '') === 'unassigned') {
            $query->whereNull('assigned_user_id');
        }
        if (isset($data['q'])) {
            if (preg_match('/^(?:CL-)?([0-9]+)$/iD', $data['q'], $matches)) {
                $number = ltrim($matches[1], '0');
                if ($number === '' || strlen($number) > 19 || (strlen($number) === 19 && strcmp($number, '9223372036854775807') > 0)) {
                    throw ValidationException::withMessages(['q' => 'Invalid ticket number.']);
                }
                $query->where('ticket_number', $number);
            } else {
                $query->whereRaw("to_tsvector('english', subject || ' ' || description) @@ plainto_tsquery('english', ?)", [$data['q']]);
            }
        }
        $direction = ($data['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';

        return $this->collection($request, $query->orderBy('created_at', $direction)->orderBy('id', $direction), self::FIELDS);
    }

    public function show(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.read');
        $this->input($request, []);

        return $this->result($request, $this->record($request, 'tickets', $ticket));
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'tickets.write');
        $data = $this->input($request, [...$this->rules(), 'assigned_user_id' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807']]);
        // Match the numbering trigger's tenant lock before acquiring related rows.
        DB::table('tenants')->where('id', $request->attributes->get('tenant_id'))->lockForUpdate()->first();
        $this->relationships($request, (string) $data['client_id'], (string) $data['contact_id']);
        if (isset($data['assigned_user_id'])) {
            if ((string) $data['assigned_user_id'] !== (string) $request->user('api')->id) {
                $this->authorize($request, 'tickets.assign');
            }
            $user = $this->record($request, 'users', (string) $data['assigned_user_id'], true);
            abort_unless($user->active, 422, 'Assignee must be active.');
        }
        $id = $this->table($request, 'tickets')->insertGetId(['tenant_id' => $request->attributes->get('tenant_id'), 'created_by_user_id' => $request->user('api')->id, ...$data]);

        return $this->result($request, $this->record($request, 'tickets', (string) $id), 201);
    }

    public function update(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.write');
        $data = $this->input($request, [...$this->rules(true), 'updated_at' => ['required', 'date']]);
        $row = $this->record($request, 'tickets', $ticket, true);
        $this->version($request, $row);
        $client = (string) ($data['client_id'] ?? $row->client_id);
        $contact = (string) ($data['contact_id'] ?? $row->contact_id);
        if ($client !== (string) $row->client_id && ! isset($data['contact_id'])) {
            throw ValidationException::withMessages(['contact_id' => 'Select a contact when changing the client.']);
        }
        if ($client !== (string) $row->client_id || $contact !== (string) $row->contact_id) {
            $this->relationships($request, $client, $contact);
        }
        unset($data['updated_at']);
        if ($data) {
            $this->table($request, 'tickets')->where('id', $ticket)->update($data);
        }

        return $this->result($request, $this->record($request, 'tickets', $ticket));
    }

    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return ['client_id' => [$presence, 'required', 'integer', 'min:1', 'max:9223372036854775807'], 'contact_id' => [$presence, 'required', 'integer', 'min:1', 'max:9223372036854775807'], 'subject' => [$presence, 'required', 'string', 'max:255'], 'description' => [$presence, 'required', 'string', 'max:50000'], 'priority' => ['sometimes', 'required', Rule::in(self::PRIORITIES)]];
    }

    private function relationships(Request $request, string $client, string $contact): void
    {
        $parent = $this->record($request, 'clients', $client, true);
        $person = $this->record($request, 'contacts', $contact, true);
        abort_unless((string) $person->client_id === $client, 422, 'Contact must belong to the selected client.');
        abort_unless($parent->active && $person->active, 422, 'Select an active client and contact.');
    }

    private function result(Request $request, object $row, int $status = 200)
    {
        $data = $this->resource($row, [...self::FIELDS, 'description']);
        // Historical relations remain readable even when deactivated or soft-deleted.
        $client = $this->table($request, 'clients')->where('id', $row->client_id)->first();
        $contact = $this->table($request, 'contacts')->where('id', $row->contact_id)->first();
        $data['client'] = $client ? $this->resource($client, ['id', 'name', 'active', 'deleted_at']) : null;
        $data['contact'] = $contact ? $this->resource($contact, ['id', 'name', 'email', 'phone', 'job_title', 'active', 'deleted_at']) : null;

        return response()->json(['data' => $data], $status);
    }
}
