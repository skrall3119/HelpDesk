<?php

use App\Http\Middleware\TenantContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [TenantContext::class]);
        $middleware->prependToPriorityList(
            before: [ThrottleRequests::class, AuthenticatesRequests::class, SubstituteBindings::class],
            prepend: TenantContext::class,
        );
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response) {
            if (! request()->attributes->get('endpoint_errors') || $response->getStatusCode() < 400) {
                return $response;
            }
            $status = $response->getStatusCode();
            $original = json_decode($response->getContent(), true) ?? [];
            $code = match ($status) {
                401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict',
                413 => 'request_too_large', 422 => 'validation_failed', 429 => 'rate_limited', default => 'server_error',
            };
            $body = ['code' => $code, 'message' => $status >= 500 ? 'An internal error occurred.' : ($original['message'] ?? 'Request failed.')];
            if (isset($original['errors'])) {
                $body['errors'] = $original['errors'];
            }

            return response()->json($body, $status, ['Cache-Control' => 'no-store']);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
