<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StorePageVisitRequest extends FormRequest
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
            'path' => ['required', 'string', 'max:2048'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'language' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'screen' => ['nullable', 'string', 'max:32'],
        ];
    }
}
