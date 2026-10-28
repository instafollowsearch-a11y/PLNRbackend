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
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'invite_token' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
