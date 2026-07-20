<?php

namespace App\Http\Requests\Api\V1\PlanSessions\Rules;

class DateNightAnswerRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $genderRule = ['required', 'string', 'in:man,woman,prefer_not_to_answer'];

        return [
            'answers.self_gender' => $genderRule,
            'answers.partner_gender' => $genderRule,
            'answers.city' => ['required', 'string', 'max:255'],
            'answers.timeframe' => ['required', 'string', 'max:255'],
            'answers.partner_interests' => ['required', 'string', 'min:3'],
            'answers.budget' => ['required', 'numeric', 'min:1'],
            'answers.event_count' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }
}
