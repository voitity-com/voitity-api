<?php

namespace App\Http\Requests\Profile;

use App\Models\ProfileAppearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_key' => [
                'required_without:background_type',
                'string',
                Rule::in(array_keys(config('profile-appearance.templates', []))),
            ],
            'background_type' => [
                'required_without:template_key',
                'string',
                Rule::in([
                    ProfileAppearance::BACKGROUND_CSS,
                    ProfileAppearance::BACKGROUND_IMAGE,
                ]),
            ],
        ];
    }
}
