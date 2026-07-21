<?php

namespace App\Services;

use App\Jobs\SendContactSubmissionNotification;
use App\Models\ContactSubmission;
use Illuminate\Http\Request;

class ContactSubmissionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data, Request $request): ContactSubmission
    {
        $submission = ContactSubmission::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone_country_code' => $data['phone_country_code'],
            'phone_number' => $data['phone_number'],
            'message' => $data['message'],
            'locale' => $data['locale'] ?? 'en',
            'source' => $data['source'] ?? 'landing_page',
            'consent_accepted_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => array_filter([
                'page_url' => $data['page_url'] ?? null,
                'referrer' => $data['referrer'] ?? null,
            ], fn (mixed $value): bool => filled($value)),
        ]);

        SendContactSubmissionNotification::dispatch($submission);

        return $submission;
    }
}
