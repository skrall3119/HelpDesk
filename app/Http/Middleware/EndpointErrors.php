<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EndpointErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('endpoint_errors', true);
        abort_if(strlen($request->getContent()) > 131072, 413, 'Request exceeds 128 KiB.');

        return $next($request);
    }
}
