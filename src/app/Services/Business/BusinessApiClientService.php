<?php

namespace App\Services\Business;

use App\Models\Business;
use App\Models\BusinessApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessApiClientService
{
    /** @param array<int, string> $origins @return array{client: BusinessApiClient, key: string} */
    public function create(Business $business, string $name, array $origins, ?string $expiresAt = null): array
    {
        $key = 'biz_pk_'.Str::random(48);
        $normalized = collect($origins)->map(fn (string $origin): string => $this->normalizeOrigin($origin))->unique()->values();

        $client = DB::transaction(function () use ($business, $name, $normalized, $expiresAt, $key): BusinessApiClient {
            $client = $business->apiClients()->create([
                'name' => $name,
                'public_id' => (string) Str::uuid(),
                'key_prefix' => substr($key, 0, 15),
                'key_hash' => hash('sha256', $key),
                'expires_at' => $expiresAt,
            ]);
            foreach ($normalized as $origin) {
                $client->origins()->create(['origin' => $origin]);
            }

            return $client;
        });

        return ['client' => $client->load('origins'), 'key' => $key];
    }

    public function normalizeOrigin(string $value): string
    {
        $parts = parse_url(trim($value));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($path, ['', '/'], true)) {
            throw ValidationException::withMessages(['origins' => "El origen {$value} no es válido."]);
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }
}
