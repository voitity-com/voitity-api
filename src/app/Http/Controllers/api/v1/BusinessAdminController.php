<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Services\Features\FeatureService;
use Illuminate\Http\Request;

abstract class BusinessAdminController extends Controller
{
    protected function ensureAvailable(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Business is only available to administrators.');
        abort_unless(app(FeatureService::class)->isGlobalEnabled(FeatureService::BUSINESS), 404, 'Business is not enabled.');
    }
}
