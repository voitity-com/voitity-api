<?php

namespace App\Providers;

use App\Classes\PaymentService\PaymentClient;
use App\Classes\PaymentService\PaymentManager;
use App\Classes\PaymentService\PaymentService;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentManager::class, fn ($app) => new PaymentManager($app));

        $this->app->bind(PaymentClient::class, function ($app) {
            return $app->make(PaymentManager::class)->driver();
        });

        $this->app->bind(PaymentService::class, function ($app) {
            return new PaymentService($app->make(PaymentClient::class));
        });
    }

    public function boot(): void
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['payment'];
    }
}
