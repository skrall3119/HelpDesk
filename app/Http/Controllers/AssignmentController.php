<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssignmentController extends EndpointController
{
    private const FIELDS = ['id', 'assigned_user_id', 'status', 'closed_at', 'updated_at'];

    public function assignees(Request $request)
    {
        $this->authorize($request, 'tickets.read');
        $this->input($request, ['page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer']]);

        return $this->collection($request, $this->table($request, 'users')->where('active', true)->whereNull('deleted_at')->orderBy('name')->orderBy('id'), ['id', 'name', 'role']);
    }

    public function claim(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.claim');
        $this->input($request, []);
        $actor = $this->record($request, 'users', (string) $request->user('api')->id, true);
        abort_unless($actor->active, 401);
        $this->record($request, 'tickets', $ticket);
        $won = $this->table($request, 'tickets')->where('id', $ticket)->whereNull('deleted_at')->whereNull('assigned_user_id')->update(['assigned_user_id' => $actor->id]);
        $row = $this->record($request, 'tickets', $ticket);
        if (! $won) {
            return response()->json(['code' => 'conflict', 'message' => 'Ticket is already assigned.', 'assigned_user_id' => (string) $row->assigned_user_id], 409);
        }

        return $this->result($row);
    }

    public function assign(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.assign');
        $data = $this->input($request, ['assigned_user_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'], 'updated_at' => ['required', 'date']]);
        $target = $this->record($request, 'users', (string) $data['assigned_user_id'], true);
        abort_unless($target->active, 422, 'Assignee must be active.');
        $row = $this->record($request, 'tickets', $ticket, true);
        $this->version($request, $row);
        $this->table($request, 'tickets')->where('id', $ticket)->update(['assigned_user_id' => $target->id]);

        return $this->result($this->record($request, 'tickets', $ticket));
    }

    public function unassign(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.assign');
        $this->input($request, ['updated_at' => ['required', 'date'], 'reopen_reason' => ['sometimes', 'required', 'string', 'max:2000']]);
        $row = $this->record($request, 'tickets', $ticket, true);
        $this->version($request, $row);
        $changes = ['assigned_user_id' => null];
        if ($row->status === 'closed') {
            $request->validate(['reopen_reason' => ['required', 'string', 'max:2000']]);
            DB::selectOne("SELECT set_config('app.status_reason', ?, true)", [$request->input('reopen_reason')]);
            $changes['status'] = 'in_progress';
        }
        $this->table($request, 'tickets')->where('id', $ticket)->update($changes);

        return $this->result($this->record($request, 'tickets', $ticket));
    }

    private function result(object $row)
    {
        return response()->json(['data' => $this->resource($row, self::FIELDS)]);
    }
}
