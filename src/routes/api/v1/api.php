<?php

use Illuminate\Support\Facades\Route;


use App\Http\Controllers\api\v1\TestController;
use App\Http\Controllers\api\v1\AuthController;



Route::get('health', function() {
    return response()->json(['message' => 'ok']);
});

Route::get('/test', [TestController::class, 'index'])->middleware(['auth:sanctum', 'abilities:test:test']);

Route::prefix('/auth')->group(function() {
    Route::post('/get-token', [AuthController::class, 'getToken']);
});
