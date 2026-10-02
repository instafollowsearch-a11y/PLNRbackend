<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            if ($user === null) {
                return;
            }

            $currentPassword = $this->input('current_password');
            if (! is_string($currentPassword) || ! Hash::check($currentPassword, $user->password)) {
                $validator->errors()->add('current_password', 'The current password is incorrect.');

                return;
            }

            $nextPassword = $this->input('password');
            if (is_string($nextPassword) && Hash::check($nextPassword, $user->password)) {
                $validator->errors()->add('password', 'The new password must be different from the current password.');
            }
        });
    }
}
