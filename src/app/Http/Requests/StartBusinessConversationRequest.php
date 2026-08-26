<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartBusinessConversationRequest extends FormRequest
{
    private const ATTRIBUTION_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'gclid',
        'gbraid',
        'wbraid',
        'landing_page',
        'referrer',
        'ga_client_id',
        'ga_session_id',
        'chat_location',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('locale')) {
            $this->merge(['locale' => mb_strtolower(trim((string) $this->input('locale')))]);
        }
    }

    public function rules(): array
    {
        return [
            'visitor_id' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(['es', 'en'])],
            'attribution' => ['nullable', 'array:'.implode(',', self::ATTRIBUTION_KEYS)],
            'attribution.utm_source' => ['nullable', 'string', 'max:255'],
            'attribution.utm_medium' => ['nullable', 'string', 'max:255'],
            'attribution.utm_campaign' => ['nullable', 'string', 'max:255'],
            'attribution.utm_term' => ['nullable', 'string', 'max:255'],
            'attribution.utm_content' => ['nullable', 'string', 'max:255'],
            'attribution.gclid' => ['nullable', 'string', 'max:255'],
            'attribution.gbraid' => ['nullable', 'string', 'max:255'],
            'attribution.wbraid' => ['nullable', 'string', 'max:255'],
            'attribution.landing_page' => ['nullable', 'url:http,https', 'max:2048'],
            'attribution.referrer' => ['nullable', 'url:http,https', 'max:2048'],
            'attribution.ga_client_id' => ['nullable', 'string', 'max:100'],
            'attribution.ga_session_id' => ['nullable', 'string', 'max:100'],
            'attribution.chat_location' => ['nullable', 'string', Rule::in(['landing', 'contact'])],
        ];
    }
}
