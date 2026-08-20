<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessApiClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'origins' => ['required', 'array', 'min:1', 'max:20'],
            'origins.*' => ['required', 'url:http,https', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
