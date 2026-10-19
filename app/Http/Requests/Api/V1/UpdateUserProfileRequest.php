<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\NormalizesEmail;
use App\Support\Email;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserProfileRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
            'current_password' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'interests' => ['sometimes', 'array', 'max:20'],
            'interests.*' => ['string', 'max:80'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            if ($user === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $email = $this->input('email');
            if (! is_string($email) || Email::normalize($email) === Email::normalize((string) $user->email)) {
                return;
            }

            $currentPassword = $this->input('current_password');
            if (! is_string($currentPassword) || $currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
                $validator->errors()->add('current_password', 'The current password is incorrect.');
            }
        });
    }
}
