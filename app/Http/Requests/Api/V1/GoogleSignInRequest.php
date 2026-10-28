<?php

namespace App\Http\Requests\Api\V1;

use App\Support\AgeGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GoogleSignInRequest extends FormRequest
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
            'id_token' => ['required_without:access_token', 'nullable', 'string'],
            'access_token' => ['required_without:id_token', 'nullable', 'string'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'invite_token' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    // No age validation: account creation no longer collects date of birth.
}
