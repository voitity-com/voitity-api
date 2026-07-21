<?php

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CloudflareTurnstileClient implements CaptchaClient
{
    public function verify(string $token, ?string $remoteIp = null): CaptchaValidationResult
    {
        $secretKey = (string) config('captcha.turnstile.secret_key', '');

        if (! filled($secretKey)) {
            Log::warning('Turnstile secret key is not configured.');

            return CaptchaValidationResult::failure(['missing-input-secret']);
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('captcha.turnstile.timeout', 5))
                ->post((string) config('captcha.turnstile.siteverify_url'), array_filter([
                    'secret' => $secretKey,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ], fn (?string $value): bool => filled($value)));
        } catch (Throwable $e) {
            Log::warning('Turnstile verification request failed.', [
                'message' => $e->getMessage(),
            ]);

            return CaptchaValidationResult::failure(['verification-request-failed']);
        }

        if (! $response->ok()) {
            Log::warning('Turnstile verification returned an unsuccessful HTTP response.', [
                'status' => $response->status(),
            ]);

            return CaptchaValidationResult::failure(['verification-http-error']);
        }

        $payload = $response->json();

        return (bool) ($payload['success'] ?? false)
            ? CaptchaValidationResult::success()
            : CaptchaValidationResult::failure($payload['error-codes'] ?? []);
    }
}
