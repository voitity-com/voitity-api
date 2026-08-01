<?php

namespace App\Services\Insights;

class AnonymousVisitor
{
    public function hash(?string $identifier): ?string
    {
        $identifier = trim((string) $identifier);

        if ($identifier === '' || strlen($identifier) > 128) {
            return null;
        }

        return hash_hmac('sha256', $identifier, (string) config('insights.visitor_hash_key'));
    }
}
