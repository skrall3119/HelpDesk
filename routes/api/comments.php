<?php

use App\Http\Controllers\CommentsController;
use Illuminate\Support\Facades\Route;

Route::get('tickets/{ticket}/comments', [CommentsController::class, 'index']);
Route::post('tickets/{ticket}/comments', [CommentsController::class, 'store']);
Route::get('tickets/{ticket}/comments/{comment}', [CommentsController::class, 'show']);
Route::patch('tickets/{ticket}/comments/{comment}', [CommentsController::class, 'update']);
Route::delete('tickets/{ticket}/comments/{comment}', [CommentsController::class, 'delete']);
Route::get('tickets/{ticket}/comments/{comment}/revisions', [CommentsController::class, 'revisions']);
