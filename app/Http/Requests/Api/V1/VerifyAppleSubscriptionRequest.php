<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyAppleSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transaction_id' => ['required', 'string', 'max:64'],
            'product_id' => ['required', 'string', Rule::in([(string) config('services.apple.product_id')])],
        ];
    }
}
