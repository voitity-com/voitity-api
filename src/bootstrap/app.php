<?php

use App\Http\Middleware\AuthenticateBusinessClient;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\HandleCors;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SyncUsdCopRateConfig;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: [
            __DIR__.'/../routes/api/v1/api.php',
        ],
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(
            Illuminate\Http\Middleware\HandleCors::class,
            HandleCors::class,
        );
        $middleware->alias([
            'force.json' => ForceJsonResponse::class,
            'sync.usd-cop-rate' => SyncUsdCopRateConfig::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'business.client' => AuthenticateBusinessClient::class,
        ]);
        $middleware->appendToGroup('api', 'force.json');
        $middleware->appendToGroup('api', SecurityHeaders::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });
    })->create();
