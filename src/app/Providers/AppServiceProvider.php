<?php

namespace App\Providers;

use App\Classes\BusinessDecisionAI\BusinessDecisionAI;
use App\Classes\BusinessDecisionAI\LocalBusinessDecisionAI;
use App\Classes\BusinessDecisionAI\OpenAIBusinessDecisionAI;
use App\Classes\BusinessFlowAI\BusinessFlowAI;
use App\Classes\BusinessFlowAI\LocalBusinessFlowAI;
use App\Classes\BusinessInstructionAI\BusinessInstructionAI;
use App\Classes\BusinessInstructionAI\LocalBusinessInstructionAI;
use App\Classes\BusinessInstructionAI\OpenAIBusinessInstructionAI;
use App\Models\Profile;
use App\Models\ProfileFact;
use App\Models\ProfileIntegrationMedia;
use App\Models\ProfileProduct;
use App\Models\ProfileSource;
use App\Models\ProfileSourceItem;
use App\Observers\ProfileKnowledgeSourceObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BusinessFlowAI::class, LocalBusinessFlowAI::class);
        $this->app->bind(BusinessDecisionAI::class, function ($app): BusinessDecisionAI {
            return config('business-ai.decision.driver') === 'local'
                ? $app->make(LocalBusinessDecisionAI::class)
                : $app->make(OpenAIBusinessDecisionAI::class);
        });
        $this->app->bind(BusinessInstructionAI::class, function ($app): BusinessInstructionAI {
            return config('business-ai.instruction.driver') === 'local'
                ? $app->make(LocalBusinessInstructionAI::class)
                : $app->make(OpenAIBusinessInstructionAI::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([
            Profile::class,
            ProfileSource::class,
            ProfileSourceItem::class,
            ProfileFact::class,
            ProfileIntegrationMedia::class,
            ProfileProduct::class,
        ] as $model) {
            $model::observe(ProfileKnowledgeSourceObserver::class);
        }

        RateLimiter::for('contact-submissions', function (Request $request) {
            return Limit::perMinute((int) config('contact.rate_limit_per_minute', 5))
                ->by((string) $request->ip());
        });

        RateLimiter::for('support-requests', function (Request $request) {
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perMinute(max(
                1,
                (int) config('support.rate_limit_per_minute', 5)
            ))->by($userId.':'.$request->ip());
        });

        RateLimiter::for('profile-messages', function (Request $request) {
            $profile = $request->route('profile');
            $profileId = $profile instanceof Profile ? $profile->id : (string) $profile;

            return Limit::perMinute(max(
                1,
                (int) config('subscriptions.message_rate_limit_per_minute', 20)
            ))->by($request->ip().':'.$profileId);
        });

        RateLimiter::for('public-profile-reads', function (Request $request) {
            return Limit::perMinute(max(
                1,
                (int) config('public-profiles.read_rate_limit_per_minute', 120)
            ))->by((string) $request->ip());
        });

        RateLimiter::for('business-chat', function (Request $request) {
            return Limit::perMinute(60)->by((string) $request->ip().':'.(string) $request->header('X-Bigmelo-Business-Key'));
        });

        RateLimiter::for('profile-interactions', function (Request $request) {
            $profile = $request->route('profile');
            $profileId = $profile instanceof Profile ? $profile->id : (string) $profile;

            return Limit::perMinute(120)->by($request->ip().':'.$profileId);
        });

        RateLimiter::for('payment-method-management', function (Request $request) {
            $userId = $request->user()?->id ?? 'guest';

            return Limit::perMinute(max(
                1,
                (int) config('payment.management_rate_limit_per_minute', 10)
            ))->by($userId.':'.$request->ip());
        });
    }
}
