<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:100',
            'description' => 'sometimes|string|max:500',
            'genre' => 'sometimes|string|max:10',
            'personality' => 'sometimes|string|max:200',
            'active' => 'sometimes|boolean',
        ];
    }
}
