<?php

namespace App\Http\Requests;

use App\Enums\BusinessLeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBusinessLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_field' => ['sometimes', Rule::in(['created_at', 'updated_at'])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => [Rule::enum(BusinessLeadStatus::class)],
            'timezone' => ['sometimes', 'timezone'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
