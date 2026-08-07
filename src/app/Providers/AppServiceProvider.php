<?php

namespace App\Providers;

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
        //
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
