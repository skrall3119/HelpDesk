<?php

use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('admin/settings', [SettingsController::class, 'show']);
Route::patch('admin/settings', [SettingsController::class, 'update']);
