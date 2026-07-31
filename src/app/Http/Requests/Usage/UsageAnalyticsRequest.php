<?php

namespace App\Http\Requests\Usage;

use Illuminate\Foundation\Http\FormRequest;

class UsageAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['nullable', 'in:day,month'],
            'timezone' => ['nullable', 'timezone'],
        ];
    }
}
