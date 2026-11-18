<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

abstract class EndpointController extends Controller
{
    protected function authorize(Request $request, string $capability): void
    {
        Gate::forUser($request->user('api'))->authorize($capability);
    }

    protected function table(Request $request, string $table): Builder
    {
        return DB::table($table)->where('tenant_id', $request->attributes->get('tenant_id'));
    }

    protected function record(Request $request, string $table, string $id, bool $lock = false, bool $deleted = false): object
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,18}$/D', $id) && (strlen($id) < 19 || strcmp($id, '9223372036854775807') <= 0), 404);
        $query = $this->table($request, $table)->where('id', $id);
        $deleted ? $query->whereNotNull('deleted_at') : $query->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? abort(404);
    }

    protected function input(Request $request, array $rules): array
    {
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This field is not supported.'));
        }

        return $request->validate($rules);
    }

    protected function version(Request $request, object $row): void
    {
        $request->validate(['updated_at' => ['required', 'date']]);
        abort_unless(CarbonImmutable::parse($row->updated_at)->equalTo(CarbonImmutable::parse($request->input('updated_at'))), 409, 'Record changed. Reload before retrying.');
    }

    protected function resource(object $row, array $fields): array
    {
        $data = [];
        foreach ($fields as $field) {
            $value = $row->$field ?? null;
            if ($value !== null && ($field === 'id' || str_ends_with($field, '_id') || $field === 'ticket_number')) {
                $value = (string) $value;
            }
            $data[$field] = $value;
        }

        return $data;
    }

    protected function collection(Request $request, Builder $query, array $fields): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = $query->paginate((int) $request->input('per_page', 25), ['*'], 'page', (int) $request->input('page', 1));

        return response()->json(['data' => array_map(fn ($row) => $this->resource($row, $fields), $page->items()), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }
}
