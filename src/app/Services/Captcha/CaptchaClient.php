<?php

namespace App\Services\Captcha;

interface CaptchaClient
{
    public function verify(string $token, ?string $remoteIp = null): CaptchaValidationResult;
}
