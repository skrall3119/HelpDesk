<?php

use App\Http\Controllers\AuthenticationController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthenticationController::class, 'login'])->middleware('throttle:login');
Route::middleware('auth:api')->group(function (): void {
    Route::get('me', [AuthenticationController::class, 'me']);
    Route::post('logout', [AuthenticationController::class, 'logout']);
});
