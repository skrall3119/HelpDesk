<?php

use App\Http\Controllers\TicketsController;
use Illuminate\Support\Facades\Route;

Route::get('tickets', [TicketsController::class, 'index']);
Route::post('tickets', [TicketsController::class, 'store']);
Route::get('tickets/{ticket}', [TicketsController::class, 'show']);
Route::patch('tickets/{ticket}', [TicketsController::class, 'update']);
