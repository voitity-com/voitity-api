<?php

namespace App\Services\Captcha;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CaptchaService
{
    public function validate(?string $token, ?string $remoteIp = null): CaptchaValidationResult
    {
        if (! (bool) config('captcha.enabled', false)) {
            return CaptchaValidationResult::success();
        }

        if (! filled($token)) {
            return CaptchaValidationResult::failure(['missing-input-response']);
        }

        return $this->client()->verify($token, $remoteIp);
    }

    public function validateOrFail(?string $token, ?string $remoteIp = null): void
    {
        $result = $this->validate($token, $remoteIp);

        if ($result->success) {
            return;
        }

        throw ValidationException::withMessages([
            'captcha_token' => ['Captcha verification failed. Please try again.'],
        ]);
    }

    private function client(): CaptchaClient
    {
        return match ((string) config('captcha.driver', 'turnstile')) {
            'turnstile' => new CloudflareTurnstileClient,
            default => throw new InvalidArgumentException('Unsupported captcha driver.'),
        };
    }
}
