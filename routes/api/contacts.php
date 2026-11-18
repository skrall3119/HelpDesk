<?php

use App\Http\Controllers\ContactsController;
use Illuminate\Support\Facades\Route;

Route::get('clients/{client}/contacts', [ContactsController::class, 'index']);
Route::post('clients/{client}/contacts', [ContactsController::class, 'store']);
Route::get('clients/{client}/contacts/{contact}', [ContactsController::class, 'show']);
Route::patch('clients/{client}/contacts/{contact}', [ContactsController::class, 'update']);
Route::post('clients/{client}/contacts/{contact}/primary', [ContactsController::class, 'primary']);
Route::post('clients/{client}/contacts/{contact}/deactivate', [ContactsController::class, 'lifecycle'])->defaults('active', false);
Route::post('clients/{client}/contacts/{contact}/restore', [ContactsController::class, 'lifecycle'])->defaults('active', true);
