<?php

use Illuminate\Support\Facades\Route;


use App\Http\Controllers\api\v1\TestController;
use App\Http\Controllers\api\v1\AuthController;
use App\Http\Controllers\api\v1\ProfileController;
use App\Http\Controllers\api\v1\ProfileChatController;
use App\Http\Controllers\api\v1\MessageController;
use App\Http\Controllers\api\v1\UserController;
use App\Http\Controllers\api\v1\AvatarController;
use App\Http\Controllers\api\v1\VoiceController;
use App\Http\Controllers\api\v1\VoiceSampleController;

Route::get('health', function() {
    return response()->json(['message' => 'ok']);
});

Route::get('/test', [TestController::class, 'index'])->middleware(['auth:sanctum', 'abilities:test:test']);
Route::get('/user', [UserController::class, 'show'])->middleware(['auth:sanctum', 'abilities:user:read']);


Route::prefix('/auth')->group(function() {
    Route::post('/get-token', [AuthController::class, 'getToken']);
    Route::post('/google/sign-in', [AuthController::class, 'googleSignIn']);
    Route::post('/google/sign-up', [AuthController::class, 'googleSignUp']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum']);
});

Route::prefix('/profile')->group(function() {
    Route::get('', [ProfileController::class, 'index'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('', [ProfileController::class, 'store'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/chats', [ProfileChatController::class, 'listChats'])->middleware(['auth:sanctum', 'abilities:chat:read']);
    Route::get('/alias/{alias}', [ProfileController::class, 'getProfileByAlias'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::get('/{profile}', [ProfileController::class, 'show'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::patch('/{profile}', [ProfileController::class, 'update'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::put('/{profile}/data', [ProfileController::class, 'updateData'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/chats', [ProfileChatController::class, 'listChats'])->middleware(['auth:sanctum', 'abilities:chat:read']);
    Route::post('/{profile}/messages', [MessageController::class, 'store'])->middleware(['auth:sanctum', 'abilities:messages:write']);
});

Route::prefix('/voice')->group(function() {
    Route::post('', [VoiceController::class, 'store'])->middleware(['auth:sanctum', 'abilities:voice:write']);
    Route::post('/test', [VoiceController::class, 'test'])->middleware(['auth:sanctum', 'abilities:voice:use']);
    Route::post('/{voice}/sample', [VoiceSampleController::class, 'store'])->middleware(['auth:sanctum', 'abilities:voice:write']);
    Route::post('/{voice}/sample/{voice_sample}/process', [VoiceSampleController::class, 'process'])->middleware(['auth:sanctum', 'abilities:voice:write']);
});

Route::prefix('/avatar')->group(function() {
    Route::post('/generate', [AvatarController::class, 'generateAvatar'])->middleware(['auth:sanctum', 'abilities:avatar:write']);
    Route::get('/{profile}', [AvatarController::class, 'show'])->middleware(['auth:sanctum', 'abilities:avatar:read']);
});
