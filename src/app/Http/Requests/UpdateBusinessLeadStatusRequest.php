<?php

namespace App\Http\Requests;

use App\Enums\BusinessLeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessLeadStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(BusinessLeadStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
