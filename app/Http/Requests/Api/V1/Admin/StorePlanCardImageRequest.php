<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanCardImageRequest extends FormRequest
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
            'plan_type' => ['required', 'string', Rule::in(AppSettings::PLAN_CARD_TYPES)],
            'url' => ['nullable', 'string', 'max:2000', 'regex:/^https?:\\/\\//i'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $hasUrl = is_string($this->input('url')) && trim((string) $this->input('url')) !== '';
            $hasFile = $this->hasFile('image');

            if (! $hasUrl && ! $hasFile) {
                $validator->errors()->add('image', 'Add an image URL or choose a file.');
            }
        });
    }
}
