<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentsController extends EndpointController
{
    private const FIELDS = ['id', 'ticket_id', 'user_id', 'body', 'created_at', 'updated_at', 'deleted_at', 'deleted_by_user_id'];

    public function index(Request $request, string $ticket)
    {
        $this->authorize($request, 'tickets.read');
        $this->input($request, [...$this->readRules(), 'page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer']]);
        $this->parent($request, $ticket);
        $query = $this->table($request, 'ticket_comments')->where('ticket_id', $ticket);
        $request->boolean('deleted') ? $query->whereNotNull('deleted_at') : $query->whereNull('deleted_at');

        return $this->collection($request, $query->orderBy('created_at')->orderBy('id'), self::FIELDS);
    }

    public function show(Request $request, string $ticket, string $comment)
    {
        $this->authorize($request, 'tickets.read');
        $this->input($request, $this->readRules());
        $this->parent($request, $ticket);

        return $this->result($this->comment($request, $ticket, $comment, deleted: $request->boolean('deleted')));
    }

    public function revisions(Request $request, string $ticket, string $comment)
    {
        $this->authorize($request, 'tickets.read');
        $this->input($request, [...$this->readRules(), 'page' => ['sometimes', 'integer'], 'per_page' => ['sometimes', 'integer']]);
        $this->parent($request, $ticket);
        $this->comment($request, $ticket, $comment, deleted: $request->boolean('deleted'));

        return $this->collection($request, $this->table($request, 'comment_revisions')->where('comment_id', $comment)->orderBy('created_at')->orderBy('id'), ['id', 'comment_id', 'editor_user_id', 'body', 'original_updated_at', 'created_at']);
    }

    public function store(Request $request, string $ticket)
    {
        $this->authorize($request, 'comments.write');
        $data = $this->input($request, ['body' => ['required', 'string', 'max:20000']]);
        $this->record($request, 'tickets', $ticket, true);
        $id = $this->table($request, 'ticket_comments')->insertGetId(['tenant_id' => $request->attributes->get('tenant_id'), 'ticket_id' => $ticket, 'user_id' => $request->user('api')->id, 'body' => $data['body']]);

        return $this->result($this->comment($request, $ticket, (string) $id), 201);
    }

    public function update(Request $request, string $ticket, string $comment)
    {
        $this->authorize($request, 'comments.write');
        $data = $this->input($request, ['body' => ['required', 'string', 'max:20000'], 'updated_at' => ['required', 'date']]);
        $this->record($request, 'tickets', $ticket, true);
        $row = $this->comment($request, $ticket, $comment, true);
        abort_unless((string) $row->user_id === (string) $request->user('api')->id || Gate::forUser($request->user('api'))->allows('comments.edit_any'), 403);
        $this->version($request, $row);
        if ($row->body !== $data['body']) {
            $this->table($request, 'comment_revisions')->insert(['tenant_id' => $request->attributes->get('tenant_id'), 'comment_id' => $comment, 'editor_user_id' => $request->user('api')->id, 'body' => $row->body, 'original_updated_at' => $row->updated_at]);
            $this->table($request, 'ticket_comments')->where('id', $comment)->update(['body' => $data['body']]);
        }

        return $this->result($this->comment($request, $ticket, $comment));
    }

    public function delete(Request $request, string $ticket, string $comment)
    {
        $this->authorize($request, 'comments.moderate');
        $this->input($request, ['updated_at' => ['required', 'date']]);
        $this->record($request, 'tickets', $ticket, true);
        $row = $this->comment($request, $ticket, $comment, true);
        $this->version($request, $row);
        $this->table($request, 'ticket_comments')->where('id', $comment)->update(['deleted_at' => now(), 'deleted_by_user_id' => $request->user('api')->id]);

        return response()->noContent();
    }

    private function readRules(): array
    {
        return ['deleted' => ['sometimes', 'boolean'], 'deleted_ticket' => ['sometimes', 'boolean']];
    }

    private function parent(Request $request, string $ticket): void
    {
        if ($request->boolean('deleted')) {
            $this->authorize($request, 'comments.moderate');
        }
        if ($request->boolean('deleted_ticket')) {
            $this->authorize($request, 'tickets.restore');
        }
        $this->record($request, 'tickets', $ticket, deleted: $request->boolean('deleted_ticket'));
    }

    private function comment(Request $request, string $ticket, string $comment, bool $lock = false, bool $deleted = false): object
    {
        $row = $this->record($request, 'ticket_comments', $comment, $lock, $deleted);
        abort_unless((string) $row->ticket_id === $ticket, 404);

        return $row;
    }

    private function result(object $row, int $status = 200)
    {
        return response()->json(['data' => $this->resource($row, self::FIELDS)], $status);
    }
}
