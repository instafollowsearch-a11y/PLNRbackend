<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateBillingCheckoutRequest extends FormRequest
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
            'success_url' => ['required', 'string', 'max:500', 'url'],
            'cancel_url' => ['required', 'string', 'max:500', 'url'],
        ];
    }
}
