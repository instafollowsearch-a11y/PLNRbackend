<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserProfileRequest extends FormRequest
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
            if (! is_string($email) || strcasecmp(trim($email), $user->email) === 0) {
                return;
            }

            $currentPassword = $this->input('current_password');
            if (! is_string($currentPassword) || $currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
                $validator->errors()->add('current_password', 'The current password is incorrect.');
            }
        });
    }
}
