<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProfileDataRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        if ($this->isUpdatingNetworks()) {
            return [
                'networks' => ['present', 'array'],
                'networks.*' => ['required', 'string', 'url:http,https', 'max:2048'],
            ];
        }

        return [
            'data' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->isUpdatingNetworks()) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $networks = $this->input('networks', []);

            if (! is_array($networks)) {
                return;
            }

            $allowedNetworks = array_keys((array) config('social-networks.networks', []));

            foreach (array_keys($networks) as $network) {
                if (! is_string($network) || ! in_array($network, $allowedNetworks, true)) {
                    $validator->errors()->add(
                        "networks.{$network}",
                        'The selected network is not supported.'
                    );
                }
            }
        });
    }

    public function isUpdatingNetworks(): bool
    {
        return str_ends_with($this->path(), '/data/networks');
    }
}
