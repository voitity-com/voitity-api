<?php

namespace App\Http\Middleware;

use App\Enums\BusinessStatus;
use App\Models\BusinessApiClient;
use App\Models\BusinessApiClientOrigin;
use App\Services\Business\BusinessApiClientService;
use App\Services\Features\FeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBusinessClient
{
    public function __construct(private readonly BusinessApiClientService $clients) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(FeatureService::class)->isGlobalEnabled(FeatureService::BUSINESS), 404);
        $origin = $this->origin($request);

        if ($request->isMethod('OPTIONS')) {
            abort_unless(BusinessApiClientOrigin::query()
                ->where('origin', $origin)
                ->whereHas('client', fn ($query) => $query
                    ->where('enabled', true)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->whereHas('business', fn ($business) => $business->where('status', BusinessStatus::Active->value)))
                ->exists(), 403, 'Origin is not allowed.');

            return $this->cors(response()->noContent(), $origin);
        }

        $key = (string) $request->header('X-Bigmelo-Business-Key');
        abort_unless(str_starts_with($key, 'biz_pk_'), 401, 'Invalid business API key.');
        $client = BusinessApiClient::query()
            ->with(['business.flow.publishedVersion', 'business.settings', 'origins'])
            ->where('key_hash', hash('sha256', $key))
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
        abort_unless($client, 401, 'Invalid business API key.');
        abort_unless($client->business->status === BusinessStatus::Active, 404, 'Business is not active.');
        abort_unless($client->origins->contains('origin', $origin), 403, 'Origin is not allowed.');

        $client->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('business_api_client', $client);
        $request->attributes->set('business', $client->business);
        $request->attributes->set('business_origin', $origin);

        return $this->cors($next($request), $origin);
    }

    private function origin(Request $request): string
    {
        $origin = trim((string) $request->header('Origin'));
        abort_if($origin === '', 403, 'Origin header is required.');

        return $this->clients->normalizeOrigin($origin);
    }

    private function cors(Response $response, string $origin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Bigmelo-Business-Key, X-Bigmelo-Business-Session, Idempotency-Key');
        $response->headers->set('Access-Control-Max-Age', '600');

        return $response;
    }
}
