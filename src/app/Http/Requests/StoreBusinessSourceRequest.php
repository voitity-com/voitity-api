<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,txt,md,csv,json'],
            'content' => ['nullable', 'string', 'max:500000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasFile('file') && trim((string) $this->input('content')) === '') {
                $validator->errors()->add('file', 'Adjunta un archivo o ingresa contenido de texto.');
            }
        });
    }
}
