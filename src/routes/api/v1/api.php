<?php

use App\Http\Controllers\api\v1\AdminUserController;
use App\Http\Controllers\api\v1\AppNotificationController;
use App\Http\Controllers\api\v1\AuthController;
use App\Http\Controllers\api\v1\AvatarController;
use App\Http\Controllers\api\v1\MessageController;
use App\Http\Controllers\api\v1\NotificationPreferenceController;
use App\Http\Controllers\api\v1\PaymentController;
use App\Http\Controllers\api\v1\ProfileAudioTranscriptionController;
use App\Http\Controllers\api\v1\ProfileChatController;
use App\Http\Controllers\api\v1\ProfileController;
use App\Http\Controllers\api\v1\ProfileKnowledgeController;
use App\Http\Controllers\api\v1\SubscriptionActionsController;
use App\Http\Controllers\api\v1\SubscriptionLimitsController;
use App\Http\Controllers\api\v1\SubscriptionPlansController;
use App\Http\Controllers\api\v1\TestController;
use App\Http\Controllers\api\v1\UserController;
use App\Http\Controllers\api\v1\VoiceController;
use App\Http\Controllers\api\v1\VoiceSampleController;
use App\Http\Controllers\api\v1\WompiWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('health', function () {
    return response()->json(['message' => 'ok']);
});

Route::get('/test', [TestController::class, 'index'])->middleware(['auth:sanctum', 'abilities:test:test']);
Route::get('/user', [UserController::class, 'show'])->middleware(['auth:sanctum', 'abilities:user:read']);
Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index'])->middleware(['auth:sanctum', 'abilities:user:read']);
Route::patch('/notification-preferences', [NotificationPreferenceController::class, 'update'])->middleware(['auth:sanctum', 'abilities:user:write']);
Route::get('/notifications', [AppNotificationController::class, 'index'])->middleware(['auth:sanctum', 'abilities:user:read']);
Route::patch('/notifications/read-all', [AppNotificationController::class, 'markAllAsRead'])->middleware(['auth:sanctum', 'abilities:user:write']);
Route::patch('/notifications/{notification}/read', [AppNotificationController::class, 'markAsRead'])->middleware(['auth:sanctum', 'abilities:user:write']);
Route::delete('/notifications/{notification}', [AppNotificationController::class, 'destroy'])->middleware(['auth:sanctum', 'abilities:user:write']);

Route::prefix('/admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->middleware(['auth:sanctum']);
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->middleware(['auth:sanctum']);
    Route::patch('/users/{user}/subscription', [AdminUserController::class, 'updateSubscription'])->middleware(['auth:sanctum']);
    Route::post('/users/{user}/impersonate', [AdminUserController::class, 'impersonate'])->middleware(['auth:sanctum']);
    Route::post('/impersonation/stop', [AdminUserController::class, 'stopImpersonation'])->middleware(['auth:sanctum']);
});

Route::prefix('/auth')->group(function () {
    Route::post('/get-token', [AuthController::class, 'getToken']);
    Route::post('/sign-up', [AuthController::class, 'signUp']);
    Route::get('/password/reset', fn () => redirect()->away(config('password-reset.redirect_url').'?'.http_build_query(request()->query())))->name('auth.password.reset.form');
    Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
    Route::post('/password/reset/validate', [AuthController::class, 'validatePasswordResetLink']);
    Route::post('/password/reset', [AuthController::class, 'resetPassword']);
    Route::post('/password/change', [AuthController::class, 'changePassword'])->middleware(['auth:sanctum']);
    Route::get('/login-history', [AuthController::class, 'loginHistory'])->middleware(['auth:sanctum']);
    Route::get('/verify-email/{user}', [AuthController::class, 'verifyEmail'])->name('auth.verify-email');
    Route::post('/google/sign-in', [AuthController::class, 'googleSignIn']);
    Route::post('/google/sign-up', [AuthController::class, 'googleSignUp']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum']);
});

Route::prefix('/profile')->group(function () {
    Route::get('', [ProfileController::class, 'index'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('', [ProfileController::class, 'store'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/professions', [ProfileKnowledgeController::class, 'professions'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::get('/chats', [ProfileChatController::class, 'listChats'])->middleware(['auth:sanctum', 'abilities:chat:read']);
    Route::get('/chats/messages', [ProfileChatController::class, 'getChatMessages'])->middleware(['auth:sanctum', 'abilities:chat:read']);
    Route::get('/social-networks', [ProfileController::class, 'socialNetworks'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::get('/alias/{alias}', [ProfileController::class, 'getProfileByAlias'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('/{profile}/transcriptions/audio', [ProfileAudioTranscriptionController::class, 'store'])->middleware(['auth:sanctum', 'abilities:profile:transcribe']);
    Route::get('/{profile}/sources', [ProfileKnowledgeController::class, 'sources'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('/{profile}/sources/cv', [ProfileKnowledgeController::class, 'storeCv'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/sources/{source}/file', [ProfileKnowledgeController::class, 'sourceFile'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('/{profile}/sources/{source}/approve', [ProfileKnowledgeController::class, 'approveSource'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/facts', [ProfileKnowledgeController::class, 'facts'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::patch('/{profile}/facts/{fact}', [ProfileKnowledgeController::class, 'updateFact'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/quality', [ProfileKnowledgeController::class, 'quality'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('/{profile}/activate', [ProfileController::class, 'activate'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::post('/{profile}/deactivate', [ProfileController::class, 'deactivate'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}', [ProfileController::class, 'show'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::patch('/{profile}', [ProfileController::class, 'update'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::put('/{profile}/data/networks', [ProfileController::class, 'updateData'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::put('/{profile}/data', [ProfileController::class, 'updateData'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/chats', [ProfileChatController::class, 'listChats'])->middleware(['auth:sanctum', 'abilities:chat:read']);
    Route::post('/{profile}/messages/audio', [MessageController::class, 'storeAudio'])->middleware(['auth:sanctum', 'abilities:messages:write']);
    Route::post('/{profile}/messages', [MessageController::class, 'store'])->middleware(['auth:sanctum', 'abilities:messages:write']);
});

Route::prefix('/voice')->group(function () {
    Route::post('', [VoiceController::class, 'store'])->middleware(['auth:sanctum', 'abilities:voice:write']);
    Route::post('/test', [VoiceController::class, 'test'])->middleware(['auth:sanctum', 'abilities:voice:use']);
    Route::patch('/{voice}', [VoiceController::class, 'update'])->middleware(['auth:sanctum', 'abilities:voice:write']);
    Route::post('/{voice}/sample', [VoiceSampleController::class, 'store'])->middleware(['auth:sanctum', 'abilities:voice:write']);
    Route::post('/{voice}/sample/{voice_sample}/process', [VoiceSampleController::class, 'process'])->middleware(['auth:sanctum', 'abilities:voice:write']);
});

Route::prefix('/avatar')->group(function () {
    Route::post('/generate', [AvatarController::class, 'generateAvatar'])->middleware(['auth:sanctum', 'abilities:avatar:write']);
    Route::get('/{profile}/history', [AvatarController::class, 'history'])->middleware(['auth:sanctum', 'abilities:avatar:read']);
    Route::post('/{profile}/activate', [AvatarController::class, 'activate'])->middleware(['auth:sanctum', 'abilities:avatar:write']);
    Route::get('/{profile}', [AvatarController::class, 'show'])->middleware(['auth:sanctum', 'abilities:avatar:read']);
});

Route::prefix('/subscription')->group(function () {
    Route::get('/plans', [SubscriptionPlansController::class, 'index'])->middleware(['auth:sanctum', 'abilities:subscription-plans:read']);
    Route::get('/limits', [SubscriptionLimitsController::class, 'show'])->middleware(['auth:sanctum', 'abilities:subscription-limits:read']);
    Route::post('/trial', [SubscriptionActionsController::class, 'startTrial'])->middleware(['auth:sanctum', 'abilities:payments:create']);
    Route::post('/trial/cancel', [SubscriptionActionsController::class, 'cancelTrial'])->middleware(['auth:sanctum', 'abilities:payments:create']);
    Route::post('/renewal/cancel', [SubscriptionActionsController::class, 'cancelRenewal'])->middleware(['auth:sanctum', 'abilities:payments:create']);
    Route::post('/renewal/reactivate', [SubscriptionActionsController::class, 'reactivateRenewal'])->middleware(['auth:sanctum', 'abilities:payments:create']);
});

Route::prefix('/payments')->group(function () {
    Route::post('/wompi/checkout', [PaymentController::class, 'createWompiCheckout'])->middleware(['auth:sanctum', 'abilities:payments:create']);
    Route::get('/{paymentOrder}', [PaymentController::class, 'show'])->middleware(['auth:sanctum', 'abilities:payments:read']);
    Route::post('/wompi/events', [WompiWebhookController::class, 'handle']);
});
