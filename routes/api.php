<?php

use App\Auth\Capabilities;
use App\Http\Controllers\AuthenticationController;
use App\Http\Middleware\EndpointErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthenticationController::class, 'login'])->middleware('throttle:login');
Route::middleware('auth:api')->group(function (): void {
    Route::get('me', [AuthenticationController::class, 'me']);
    Route::post('logout', [AuthenticationController::class, 'logout']);
});

Route::middleware([EndpointErrors::class, 'auth:api'])->group(function (): void {
    Route::get('capabilities', fn (Request $request) => response()->json(['data' => Capabilities::for($request->user('api'))]));
    foreach (glob(__DIR__.'/api/*.php') as $routes) {
        require $routes;
    }
});
