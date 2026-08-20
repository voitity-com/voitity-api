<?php

namespace App\Http\Requests;

class UpdateBusinessRequest extends StoreBusinessRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:10000']];
    }
}
