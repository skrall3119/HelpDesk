<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HistoryController extends EndpointController
{
    private const FIELDS = ['id', 'ticket_number', 'client_id', 'contact_id', 'assigned_user_id', 'created_by_user_id', 'subject', 'status', 'priority', 'created_at', 'updated_at', 'closed_at', 'deleted_at'];

    public function deleted(Request $request)
    {
        $this->authorize($request, 'tickets.restore');
        $this->input($request, ['page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer']]);

        return $this->collection($request, $this->table($request, 'tickets')->whereNotNull('deleted_at')->orderByDesc('deleted_at')->orderByDesc('id'), self::FIELDS);
    }

    public function showDeleted(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.restore');
        $this->input($request, []);

        return $this->result($request, $this->record($request, 'tickets', $ticket, deleted: true));
    }

    public function activity(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.read');
        $this->input($request, ['page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer'], 'deleted_ticket' => ['sometimes', 'boolean']]);
        if ($request->boolean('deleted_ticket')) {
            $this->authorize($request, 'tickets.restore');
        }
        $this->record($request, 'tickets', $ticket, deleted: $request->boolean('deleted_ticket'));

        return $this->collection($request, $this->table($request, 'ticket_activities')->where('ticket_id', $ticket)->orderBy('created_at')->orderBy('id'), ['id', 'ticket_id', 'user_id', 'activity_type', 'old_values', 'new_values', 'status_reason', 'created_at']);
    }

    public function delete(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.delete');
        $this->input($request, ['updated_at' => ['required', 'date']]);
        $row = $this->record($request, 'tickets', $ticket, true);
        $this->version($request, $row);
        $this->table($request, 'tickets')->where('id', $ticket)->update(['deleted_at' => now()]);

        return response()->noContent();
    }

    public function restore(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.restore');
        $this->input($request, ['updated_at' => ['required', 'date']]);
        $row = $this->record($request, 'tickets', $ticket, true, true);
        $this->version($request, $row);
        $this->table($request, 'tickets')->where('id', $ticket)->update(['deleted_at' => null]);

        return $this->result($request, $this->record($request, 'tickets', $ticket));
    }

    protected function resource(object $row, array $fields): array
    {
        $data = parent::resource($row, $fields);
        foreach (['old_values', 'new_values'] as $snapshot) {
            if (! isset($data[$snapshot])) {
                continue;
            }
            $data[$snapshot] = json_decode($data[$snapshot], true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            foreach ($data[$snapshot] as $key => $value) {
                if ($value !== null && ($key === 'id' || str_ends_with($key, '_id') || $key === 'ticket_number')) {
                    $data[$snapshot][$key] = (string) $value;
                }
            }
        }

        return $data;
    }

    private function result(Request $request, object $row)
    {
        $data = $this->resource($row, [...self::FIELDS, 'description']);
        $contact = $this->table($request, 'contacts')->where('id', $row->contact_id)->first();
        $data['contact'] = $contact ? $this->resource($contact, ['id', 'name', 'email', 'phone', 'active', 'deleted_at']) : null;

        return response()->json(['data' => $data]);
    }
}
