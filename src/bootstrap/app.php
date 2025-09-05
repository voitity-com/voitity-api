<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: [
            __DIR__.'/../routes/api/v1/api.php',
        ],
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'force.json' => App\Http\Middleware\ForceJsonResponse::class,
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
        $middleware->appendToGroup('api', 'force.json');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
