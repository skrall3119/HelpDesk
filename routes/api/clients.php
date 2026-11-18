<?php

use App\Http\Controllers\ClientsController;
use Illuminate\Support\Facades\Route;

Route::get('clients', [ClientsController::class, 'index']);
Route::post('clients', [ClientsController::class, 'store']);
Route::get('clients/{client}', [ClientsController::class, 'show']);
Route::patch('clients/{client}', [ClientsController::class, 'update']);
Route::post('clients/{client}/deactivate', [ClientsController::class, 'lifecycle'])->defaults('active', false);
Route::post('clients/{client}/restore', [ClientsController::class, 'lifecycle'])->defaults('active', true);
