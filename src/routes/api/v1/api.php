<?php

use Illuminate\Support\Facades\Route;


use App\Http\Controllers\api\v1\TestController;
use App\Http\Controllers\api\v1\AuthController;
use App\Http\Controllers\api\v1\ProfileController;
use App\Http\Controllers\api\v1\VoiceController;

Route::get('health', function() {
    return response()->json(['message' => 'ok']);
});

Route::get('/test', [TestController::class, 'index'])->middleware(['auth:sanctum', 'abilities:test:test']);


Route::prefix('/auth')->group(function() {
    Route::post('/get-token', [AuthController::class, 'getToken']);
});

Route::prefix('/profile')->group(function() {
    Route::post('', [ProfileController::class, 'store'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::patch('/{profile}', [ProfileController::class, 'update'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::put('/{profile}/data', [ProfileController::class, 'updateData'])->middleware(['auth:sanctum', 'abilities:profile:write']);
});

Route::prefix('/voice')->group(function() {
    Route::post('', [VoiceController::class, 'store'])->middleware(['auth:sanctum', 'abilities:voice:write']);
});
