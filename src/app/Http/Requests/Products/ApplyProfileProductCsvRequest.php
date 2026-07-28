<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyProfileProductCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('products.csv_max_rows', 500))],
            'rows.*.id' => ['required', 'integer', 'distinct'],
            'rows.*.action' => ['required', Rule::in(['import', 'replace', 'skip'])],
        ];
    }
}
