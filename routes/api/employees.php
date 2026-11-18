<?php

use App\Http\Controllers\EmployeesController;
use Illuminate\Support\Facades\Route;

Route::get('admin/users', [EmployeesController::class, 'index']);
Route::post('admin/users', [EmployeesController::class, 'store']);
Route::get('admin/users/{user}', [EmployeesController::class, 'show']);
Route::patch('admin/users/{user}', [EmployeesController::class, 'update']);
Route::delete('admin/users/{user}', [EmployeesController::class, 'lifecycle'])->defaults('lifecycle', 'delete');
foreach (['activate', 'deactivate', 'restore'] as $action) {
    Route::post('admin/users/{user}/'.$action, [EmployeesController::class, 'lifecycle'])->defaults('lifecycle', $action);
}
