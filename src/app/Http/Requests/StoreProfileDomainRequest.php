<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreProfileDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $hostname = strtolower(rtrim(trim((string) $this->input('hostname')), '.'));
        $this->merge(['hostname' => $hostname]);
    }

    public function rules(): array
    {
        return [
            'hostname' => [
                'required',
                'string',
                'max:253',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! $this->isValidHostname($value)) {
                        $fail('The hostname must be a valid public domain without a protocol, port, path, or wildcard.');
                    }
                },
            ],
        ];
    }

    private function isValidHostname(string $hostname): bool
    {
        if ($hostname === '' || ! str_contains($hostname, '.') || filter_var($hostname, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (! preg_match('/^[a-z0-9.-]+$/', $hostname)) {
            return false;
        }

        foreach (explode('.', $hostname) as $label) {
            if ($label === '' || strlen($label) > 63 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        $reserved = ['localhost', 'local', 'test', 'invalid', 'example', 'internal'];
        $lastLabel = (string) collect(explode('.', $hostname))->last();

        return $hostname !== 'bigmelo.com'
            && ! str_ends_with($hostname, '.bigmelo.com')
            && ! in_array($lastLabel, $reserved, true);
    }
}
