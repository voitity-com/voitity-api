<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBusinessFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'nodes' => ['required', 'array', 'min:1', 'max:250'],
            'nodes.*.key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'nodes.*.type' => ['required', Rule::in(['instruction', 'decision', 'action'])],
            'nodes.*.title' => ['required', 'string', 'max:180'],
            'nodes.*.x' => ['required', 'integer', 'min:-1000000', 'max:1000000'],
            'nodes.*.y' => ['required', 'integer', 'min:-1000000', 'max:1000000'],
            'nodes.*.config' => ['nullable', 'array'],
            'edges' => ['present', 'array', 'max:500'],
            'edges.*.key' => ['required', 'string', 'max:100'],
            'edges.*.source' => ['required', 'string', 'max:100'],
            'edges.*.target' => ['required', 'string', 'max:100'],
            'edges.*.source_handle' => ['nullable', 'string', 'max:80'],
            'edges.*.label' => ['nullable', 'string', 'max:180'],
            'edges.*.config' => ['nullable', 'array'],
        ];
    }
}
