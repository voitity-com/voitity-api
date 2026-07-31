<?php

namespace Tests\Feature\Http\Middleware;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function api_responses_include_security_headers(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'"
            )
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function secure_api_responses_include_hsts(): void
    {
        $response = $this->getJson('https://bigmelo.test/api/health');

        $response
            ->assertOk()
            ->assertHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
    }
}
