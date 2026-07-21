<?php

namespace App\Http\Middleware;

use App\Classes\UsdCopRateService\UsdCopRateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncUsdCopRateConfig
{
    /**
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app(UsdCopRateService::class)->syncConfig();

        return $next($request);
    }
}
