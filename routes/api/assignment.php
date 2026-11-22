<?php

use App\Http\Controllers\AssignmentController;
use Illuminate\Support\Facades\Route;

Route::get('assignees', [AssignmentController::class, 'assignees']);
Route::post('tickets/{ticket}/claim', [AssignmentController::class, 'claim']);
Route::post('tickets/{ticket}/assignment', [AssignmentController::class, 'assign']);
Route::post('tickets/{ticket}/unassign', [AssignmentController::class, 'unassign']);
