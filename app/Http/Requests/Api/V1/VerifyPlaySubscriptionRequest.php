<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyPlaySubscriptionRequest extends FormRequest
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
            'purchase_token' => ['required', 'string', 'max:2000'],
            'product_id' => ['required', 'string', Rule::in([(string) config('services.google_play.product_id')])],
        ];
    }
}
