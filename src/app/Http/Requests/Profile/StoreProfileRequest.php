<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'alias' => 'nullable|string|max:100',
            'description' => 'required|string|max:500',
            'genre' => 'required|string|max:10',
            'personality' => 'required|string|max:200',
        ];
    }
}
