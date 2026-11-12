<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $base = preg_quote(strtolower((string) config('tenancy.base_domain')), '/');
        if (! preg_match('/^([a-z0-9]+(?:-[a-z0-9]+)*)\.'.$base.'$/D', $request->getHost(), $matches)
            || strlen($matches[1]) > 63) {
            abort(404, 'Not found.');
        }

        $connection = DB::connection();
        $tenant = $connection->selectOne('SELECT * FROM public.lookup_tenant(?)', [$matches[1]]);
        abort_unless($tenant && $tenant->active, 404, 'Not found.');

        // Never inherit an authenticated user or database context from another request.
        Auth::forgetGuards();
        $connection->beginTransaction();
        try {
            $connection->selectOne("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenant->id]);
            $connection->selectOne("SELECT set_config('app.user_id', '', true)");
            $request->attributes->set('tenant_id', (string) $tenant->id);
            $response = $next($request);
            if ($response->getStatusCode() >= 400) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        } finally {
            Auth::forgetGuards();
        }
    }
}
