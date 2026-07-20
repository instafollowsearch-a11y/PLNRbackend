<?php

namespace App\Http\Requests\Api\V1\PlanSessions\Rules;

class NightOutAnswerRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'answers.city' => ['required', 'string', 'max:255'],
            'answers.interests' => ['required', 'string', 'min:3'],
            'answers.group_size' => ['required', 'integer', 'min:1', 'max:20'],
            'answers.budget_per_person' => ['required', 'numeric', 'min:1'],
            'answers.dates' => ['required', 'string', 'max:255'],
            'answers.start_time' => ['required', 'string', 'max:255'],
        ];
    }
}
