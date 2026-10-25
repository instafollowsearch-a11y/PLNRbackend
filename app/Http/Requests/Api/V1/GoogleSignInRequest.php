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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('date_of_birth')) {
                return;
            }

            $raw = $this->input('date_of_birth');

            if (! is_string($raw) || trim($raw) === '') {
                return;
            }

            if (! AgeGate::isOldEnough($raw)) {
                $validator->errors()->add(
                    'date_of_birth',
                    'You must be at least '.AgeGate::MINIMUM_AGE.' years old to use PLNR.',
                );
            }
        });
    }
}
