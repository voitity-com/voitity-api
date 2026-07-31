<?php

namespace App\Http\Requests\Credits;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'credits' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'payment_source_id' => ['sometimes', 'integer', 'min:1'],
            'terms_accepted' => ['accepted'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $credits = (int) $this->input('credits');
                $minimum = max(1, (int) config('subscriptions.credit_store.minimum_purchase_credits', 1000));
                $maximum = max($minimum, (int) config(
                    'subscriptions.credit_store.maximum_purchase_credits',
                    100000
                ));
                $step = max(1, (int) config('subscriptions.credit_store.purchase_step_credits', 1000));

                if ($credits < $minimum) {
                    $validator->errors()->add('credits', "A minimum of {$minimum} credits is required.");

                    return;
                }

                if ($credits > $maximum) {
                    $validator->errors()->add('credits', "A maximum of {$maximum} credits is allowed.");

                    return;
                }

                if (($credits - $minimum) % $step !== 0) {
                    $validator->errors()->add('credits', "Credits must increase in steps of {$step}.");
                }
            },
        ];
    }
}
