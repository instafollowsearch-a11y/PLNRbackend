<?php

namespace App\Http\Requests\Api\V1\PlanSessions\Rules;

class VacationAnswerRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'answers.destination' => ['required', 'string', 'max:255'],
            'answers.dates' => ['required', 'string', 'max:255'],
            'answers.budget' => ['required', 'numeric', 'min:1'],
            'answers.age_range' => ['required', 'string', 'max:255'],
            'answers.interests' => ['required', 'string', 'min:3'],
            'answers.group_size' => ['required', 'integer', 'min:1', 'max:20'],
            'answers.activity_mix' => ['required', 'string', 'min:3'],
        ];
    }
}
