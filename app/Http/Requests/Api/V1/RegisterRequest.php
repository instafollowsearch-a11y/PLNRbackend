<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\NormalizesEmail;
use App\Support\AgeGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    use NormalizesEmail;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEmailInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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

            if (! is_string($raw) || $raw === '') {
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
