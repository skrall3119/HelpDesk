<?php

use App\Http\Controllers\HistoryController;
use Illuminate\Support\Facades\Route;

Route::get('deleted-tickets', [HistoryController::class, 'deleted']);
Route::get('deleted-tickets/{ticket}', [HistoryController::class, 'showDeleted']);
Route::get('tickets/{ticket}/activity', [HistoryController::class, 'activity']);
Route::delete('tickets/{ticket}', [HistoryController::class, 'delete']);
Route::post('tickets/{ticket}/restore', [HistoryController::class, 'restore']);
