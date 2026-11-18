<?php

use App\Http\Controllers\TicketStatusController;
use Illuminate\Support\Facades\Route;

Route::post('tickets/{ticket}/status', [TicketStatusController::class, 'update']);
