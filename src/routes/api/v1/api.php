<?php

use App\Http\Controllers\api\v1\AdminFeatureController;
use App\Http\Controllers\api\v1\AdminUserController;
use App\Http\Controllers\api\v1\AppNotificationController;
use App\Http\Controllers\api\v1\AuthController;
use App\Http\Controllers\api\v1\AvatarController;
use App\Http\Controllers\api\v1\ContactSubmissionController;
use App\Http\Controllers\api\v1\CreditController;
use App\Http\Controllers\api\v1\MessageController;
use App\Http\Controllers\api\v1\NotificationPreferenceController;
use App\Http\Controllers\api\v1\PaymentController;
use App\Http\Controllers\api\v1\PaymentMethodController;
use App\Http\Controllers\api\v1\PaymentOperationsHealthController;
use App\Http\Controllers\api\v1\ProfileAudioTranscriptionController;
use App\Http\Controllers\api\v1\ProfileChatController;
use App\Http\Controllers\api\v1\ProfileController;
use App\Http\Controllers\api\v1\ProfileConversationMessageController;
use App\Http\Controllers\api\v1\ProfileFeatureController;
use App\Http\Controllers\api\v1\ProfileIntegrationController;
use App\Http\Controllers\api\v1\ProfileKnowledgeController;
use App\Http\Controllers\api\v1\ProfileMessagingCapabilitiesController;
use App\Http\Controllers\api\v1\ProfileProductController;
use App\Http\Controllers\api\v1\ProfileProductImportController;
use App\Http\Controllers\api\v1\PublicProfileController;
use App\Http\Controllers\api\v1\SubscriptionActionsController;
use App\Http\Controllers\api\v1\SubscriptionLimitsController;
use App\Http\Controllers\api\v1\SubscriptionPlansController;
use App\Http\Controllers\api\v1\TestController;
use App\Http\Controllers\api\v1\UsageAnalyticsController;
use App\Http\Controllers\api\v1\UsdCopRateController;
use App\Http\Controllers\api\v1\UserController;
use App\Http\Controllers\api\v1\VoiceController;
use App\Http\Controllers\api\v1\VoiceSampleController;
use App\Http\Controllers\api\v1\WompiWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('health', function () {
    return response()->json(['message' => 'ok']);
});
Route::get('health/payments', PaymentOperationsHealthController::class);

Route::post('/contact-submissions', [ContactSubmissionController::class, 'store'])
    ->middleware('throttle:contact-submissions');
Route::get('/subscription/public-plans', [SubscriptionPlansController::class, 'publicIndex']);

Route::prefix('/public')->group(function () {
    Route::get('/social-networks', [PublicProfileController::class, 'socialNetworks'])
        ->middleware('throttle:public-profile-reads');
    Route::get('/profiles/{alias}', [PublicProfileController::class, 'show'])
        ->middleware('throttle:public-profile-reads');
    Route::get('/profiles/{profile}/avatar', [PublicProfileController::class, 'avatar'])
        ->whereNumber('profile')
        ->middleware('throttle:public-profile-reads');
    Route::get('/profiles/{profile}/messaging-capabilities', [PublicProfileController::class, 'messagingCapabilities'])
        ->whereNumber('profile')
        ->middleware('throttle:public-profile-reads');
    Route::post('/profiles/{profile}/messages/audio', [MessageController::class, 'publicStoreAudio'])
        ->whereNumber('profile')
        ->middleware('throttle:profile-messages');
    Route::post('/profiles/{profile}/messages', [MessageController::class, 'publicStore'])
        ->whereNumber('profile')
        ->middleware('throttle:profile-messages');
});

Route::get('/test', [TestController::class, 'index'])->middleware(['auth:sanctum', 'abilities:test:test']);
Route::get('/user', [UserController::class, 'show'])->middleware(['auth:sanctum', 'abilities:user:read']);
Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index'])->middleware(['auth:sanctum', 'abilities:user:read']);
Route::patch('/notification-preferences', [NotificationPreferenceController::class, 'update'])->middleware(['auth:sanctum', 'abilities:user:write']);
Route::get('/notifications', [AppNotificationController::class, 'index'])->middleware(['auth:sanctum', 'abilities:user:read']);
Route::patch('/notifications/read-all', [AppNotificationController::class, 'markAllAsRead'])->middleware(['auth:sanctum', 'abilities:user:write']);
Route::patch('/notifications/{notification}/read', [AppNotificationController::class, 'markAsRead'])->middleware(['auth:sanctum', 'abilities:user:write']);
Route::delete('/notifications/{notification}', [AppNotificationController::class, 'destroy'])->middleware(['auth:sanctum', 'abilities:user:write']);

Route::get('/integrations/instagram/callback', [ProfileIntegrationController::class, 'instagramCallback']);
Route::get('/integrations/tiktok/callback', [ProfileIntegrationController::class, 'tiktokCallback']);

Route::prefix('/admin')->group(function () {
    Route::get('/features', [AdminFeatureController::class, 'index'])->middleware(['auth:sanctum']);
    Route::patch('/features', [AdminFeatureController::class, 'update'])->middleware(['auth:sanctum']);
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
    Route::get('/{profile}/conversation-messages', [ProfileConversationMessageController::class, 'index'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::put('/{profile}/conversation-messages', [ProfileConversationMessageController::class, 'update'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::post('/{profile}/conversation-messages/{type}/audio/generate', [ProfileConversationMessageController::class, 'generateAudio'])->middleware(['auth:sanctum', 'abilities:voice:use']);
    Route::post('/{profile}/conversation-messages/{type}/audio', [ProfileConversationMessageController::class, 'uploadAudio'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::delete('/{profile}/conversation-messages/{type}/audio', [ProfileConversationMessageController::class, 'clearAudio'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/sources', [ProfileKnowledgeController::class, 'sources'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('/{profile}/sources/cv', [ProfileKnowledgeController::class, 'storeCv'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/sources/{source}/file', [ProfileKnowledgeController::class, 'sourceFile'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('/{profile}/sources/{source}/approve', [ProfileKnowledgeController::class, 'approveSource'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/features', [ProfileFeatureController::class, 'index'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::patch('/{profile}/features', [ProfileFeatureController::class, 'update'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/integrations', [ProfileIntegrationController::class, 'index'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('/{profile}/integrations/instagram/connect-url', [ProfileIntegrationController::class, 'instagramConnectUrl'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::post('/{profile}/integrations/instagram/sync', [ProfileIntegrationController::class, 'instagramSync'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/integrations/instagram/media', [ProfileIntegrationController::class, 'instagramMedia'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::put('/{profile}/integrations/instagram/media-selection', [ProfileIntegrationController::class, 'instagramUpdateMediaSelection'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::delete('/{profile}/integrations/instagram', [ProfileIntegrationController::class, 'instagramDisconnect'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::post('/{profile}/integrations/tiktok/connect-url', [ProfileIntegrationController::class, 'tiktokConnectUrl'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::post('/{profile}/integrations/tiktok/sync', [ProfileIntegrationController::class, 'tiktokSync'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/integrations/tiktok/media', [ProfileIntegrationController::class, 'tiktokMedia'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::put('/{profile}/integrations/tiktok/media-selection', [ProfileIntegrationController::class, 'tiktokUpdateMediaSelection'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::delete('/{profile}/integrations/tiktok', [ProfileIntegrationController::class, 'tiktokDisconnect'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::post('/{profile}/integrations/onlyfans', [ProfileIntegrationController::class, 'onlyFansConnect'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/integrations/onlyfans/media', [ProfileIntegrationController::class, 'onlyFansMedia'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('/{profile}/integrations/onlyfans/media', [ProfileIntegrationController::class, 'onlyFansUploadMedia'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::put('/{profile}/integrations/onlyfans/media-selection', [ProfileIntegrationController::class, 'onlyFansUpdateMediaSelection'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::delete('/{profile}/integrations/onlyfans/media/{media}', [ProfileIntegrationController::class, 'onlyFansDeleteMedia'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::delete('/{profile}/integrations/onlyfans', [ProfileIntegrationController::class, 'onlyFansDisconnect'])->middleware(['auth:sanctum', 'abilities:profile:write']);
    Route::get('/{profile}/products', [ProfileProductController::class, 'index'])->middleware(['auth:sanctum', 'ability:products:read,profile:read']);
    Route::post('/{profile}/products', [ProfileProductController::class, 'store'])->middleware(['auth:sanctum', 'ability:products:write,profile:write']);
    Route::patch('/{profile}/products/settings', [ProfileProductController::class, 'settings'])->middleware(['auth:sanctum', 'ability:products:write,profile:write']);
    Route::patch('/{profile}/products/bulk/status', [ProfileProductController::class, 'bulkStatus'])->middleware(['auth:sanctum', 'ability:products:publish,profile:write']);
    Route::patch('/{profile}/products/bulk/destination', [ProfileProductController::class, 'bulkDestination'])->middleware(['auth:sanctum', 'ability:products:write,profile:write']);
    Route::get('/{profile}/products/imports/template', [ProfileProductImportController::class, 'template'])->middleware(['auth:sanctum', 'ability:products:import,profile:write']);
    Route::post('/{profile}/products/imports/preview', [ProfileProductImportController::class, 'preview'])->middleware(['auth:sanctum', 'ability:products:import,profile:write']);
    Route::get('/{profile}/products/imports/{productImport}', [ProfileProductImportController::class, 'show'])->middleware(['auth:sanctum', 'ability:products:import,profile:write']);
    Route::post('/{profile}/products/imports/{productImport}/apply', [ProfileProductImportController::class, 'apply'])->middleware(['auth:sanctum', 'ability:products:import,profile:write', 'ability:products:write,profile:write']);
    Route::post('/{profile}/products/{product}', [ProfileProductController::class, 'update'])->middleware(['auth:sanctum', 'ability:products:write,profile:write']);
    Route::delete('/{profile}/products/{product}', [ProfileProductController::class, 'destroy'])->middleware(['auth:sanctum', 'ability:products:write,profile:write']);
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
    Route::get('/{profile}/messaging-capabilities', [ProfileMessagingCapabilitiesController::class, 'show'])->middleware(['auth:sanctum', 'abilities:profile:read']);
    Route::post('/{profile}/messages/audio', [MessageController::class, 'storeAudio'])->middleware(['auth:sanctum', 'abilities:messages:write', 'throttle:profile-messages']);
    Route::post('/{profile}/messages', [MessageController::class, 'store'])->middleware(['auth:sanctum', 'abilities:messages:write', 'throttle:profile-messages']);
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
    Route::get('/plans', [SubscriptionPlansController::class, 'index'])->middleware(['auth:sanctum', 'abilities:subscription-plans:read', 'sync.usd-cop-rate']);
    Route::get('/limits', [SubscriptionLimitsController::class, 'show'])->middleware(['auth:sanctum', 'abilities:subscription-limits:read']);
    Route::get('/billing-state', [SubscriptionActionsController::class, 'billingState'])->middleware(['auth:sanctum', 'abilities:payments:read']);
    Route::get('/payment-source-setup', [SubscriptionActionsController::class, 'paymentSourceSetup'])->middleware(['auth:sanctum', 'abilities:payments:create', 'sync.usd-cop-rate']);
    Route::post('/payment-source', [SubscriptionActionsController::class, 'startSubscriptionWithPaymentSource'])->middleware(['auth:sanctum', 'abilities:payments:create', 'sync.usd-cop-rate']);
    Route::get('/trial/payment-source-setup', [SubscriptionActionsController::class, 'trialPaymentSourceSetup'])->middleware(['auth:sanctum', 'abilities:payments:create', 'sync.usd-cop-rate']);
    Route::post('/trial', [SubscriptionActionsController::class, 'startTrial'])->middleware(['auth:sanctum', 'abilities:payments:create', 'sync.usd-cop-rate']);
    Route::post('/trial/cancel', [SubscriptionActionsController::class, 'cancelTrial'])->middleware(['auth:sanctum', 'abilities:payments:create']);
    Route::post('/renewal/cancel', [SubscriptionActionsController::class, 'cancelRenewal'])->middleware(['auth:sanctum', 'abilities:payments:create']);
    Route::post('/renewal/reactivate', [SubscriptionActionsController::class, 'reactivateRenewal'])->middleware(['auth:sanctum', 'abilities:payments:create']);
    Route::post('/renewal/retry', [SubscriptionActionsController::class, 'retryRenewal'])->middleware(['auth:sanctum', 'abilities:payments:create', 'sync.usd-cop-rate', 'throttle:payment-method-management']);
});

Route::prefix('/payments')->group(function () {
    Route::get('/usd-cop-rate', [UsdCopRateController::class, 'show'])->middleware(['auth:sanctum', 'abilities:payments:read', 'sync.usd-cop-rate']);
    Route::post('/wompi/checkout', [PaymentController::class, 'createWompiCheckout'])->middleware(['auth:sanctum', 'abilities:payments:create', 'sync.usd-cop-rate']);
    Route::get('/{paymentOrder}', [PaymentController::class, 'show'])->middleware(['auth:sanctum', 'abilities:payments:read']);
    Route::post('/wompi/events', [WompiWebhookController::class, 'handle']);
});

Route::prefix('/payment-methods')
    ->middleware(['auth:sanctum', 'throttle:payment-method-management'])
    ->group(function () {
        Route::get('', [PaymentMethodController::class, 'index'])->middleware('abilities:payments:read');
        Route::get('/setup', [PaymentMethodController::class, 'setup'])->middleware('abilities:payments:create');
        Route::post('', [PaymentMethodController::class, 'store'])->middleware('abilities:payments:create');
        Route::patch('/{paymentSource}/default', [PaymentMethodController::class, 'makeDefault'])
            ->middleware('abilities:payments:create');
        Route::delete('/{paymentSource}', [PaymentMethodController::class, 'destroy'])
            ->middleware('abilities:payments:create');
    });

Route::prefix('/credits')->group(function () {
    Route::get('/catalog', [CreditController::class, 'catalog'])->middleware(['auth:sanctum', 'abilities:payments:read']);
    Route::get('/wallet', [CreditController::class, 'wallet'])->middleware(['auth:sanctum', 'abilities:payments:read']);
    Route::get('/purchases', [CreditController::class, 'purchases'])->middleware(['auth:sanctum', 'abilities:payments:read']);
    Route::post('/purchases', [CreditController::class, 'purchase'])->middleware(['auth:sanctum', 'abilities:payments:create', 'sync.usd-cop-rate']);
});

Route::get('/usage', [UsageAnalyticsController::class, 'index'])
    ->middleware(['auth:sanctum', 'abilities:subscription-limits:read']);
