<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketStatusController extends EndpointController
{
    private const TRANSITIONS = [
        'open' => ['in_progress', 'waiting_on_client', 'on_hold', 'closed'],
        'in_progress' => ['waiting_on_client', 'on_hold', 'closed'],
        'waiting_on_client' => ['in_progress', 'on_hold', 'closed'],
        'on_hold' => ['in_progress', 'waiting_on_client', 'closed'],
        'closed' => ['in_progress'],
    ];

    public function update(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.status');
        $data = $this->input($request, ['status' => ['required', Rule::in(array_keys(self::TRANSITIONS))], 'reason' => ['sometimes', 'required', 'string', 'max:2000'], 'updated_at' => ['required', 'date']]);
        $row = $this->record($request, 'tickets', $ticket, true);
        $this->version($request, $row);
        if ($data['status'] !== $row->status) {
            if (! in_array($data['status'], self::TRANSITIONS[$row->status], true)) {
                throw ValidationException::withMessages(['status' => 'This status transition is not allowed.']);
            }
            if ($data['status'] === 'closed' && $row->assigned_user_id === null) {
                throw ValidationException::withMessages(['status' => 'Assign the ticket before closing it.']);
            }
            if (in_array($data['status'], ['on_hold', 'closed'], true) || $row->status === 'closed') {
                $request->validate(['reason' => ['required', 'string', 'max:2000']]);
            }
            DB::selectOne("SELECT set_config('app.status_reason', ?, true)", [$data['reason'] ?? '']);
            $this->table($request, 'tickets')->where('id', $ticket)->update(['status' => $data['status']]);
            $row = $this->record($request, 'tickets', $ticket);
        }

        return response()->json(['data' => $this->resource($row, ['id', 'status', 'assigned_user_id', 'closed_at', 'updated_at'])]);
    }
}
