<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
            'free_plans_per_day' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'anthropic_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'anthropic_model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'anthropic_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_from_address' => ['sometimes', 'nullable', 'string', 'max:255', 'email'],
            'mail_from_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'booking_ops_email' => ['sometimes', 'nullable', 'string', 'max:255', 'email'],
            'rate_limit_ai_per_hour' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'clear_anthropic_api_key' => ['sometimes', 'boolean'],
            'clear_anthropic_model' => ['sometimes', 'boolean'],
            'clear_anthropic_url' => ['sometimes', 'boolean'],
            'clear_mail_from_address' => ['sometimes', 'boolean'],
            'clear_mail_from_name' => ['sometimes', 'boolean'],
            'clear_booking_ops_email' => ['sometimes', 'boolean'],
            'clear_rate_limit_ai_per_hour' => ['sometimes', 'boolean'],
        ];
    }
}
