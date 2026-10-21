<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\NormalizesEmail;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Http\FormRequest;

class StorePlanShareRequest extends FormRequest
{
    use NormalizesEmail;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEmailInput();

        if (! app(AppSettings::class)->planInviteSmsEnabled()) {
            $this->merge(['phone' => null]);

            return;
        }

        $phone = $this->input('phone');

        if (! is_string($phone)) {
            return;
        }

        $trimmed = trim($phone);

        if ($trimmed === '') {
            $this->merge(['phone' => null]);

            return;
        }

        $normalized = preg_replace('/[^\d+]/', '', $trimmed) ?? $trimmed;
        $this->merge(['phone' => $normalized]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $smsEnabled = app(AppSettings::class)->planInviteSmsEnabled();

        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => $smsEnabled
                ? ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/']
                : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a mobile number with country code, such as +15551234567.',
        ];
    }
}
