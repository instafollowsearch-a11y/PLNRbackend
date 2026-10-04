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
            'answers.arrival_time' => ['required', 'string', 'max:40'],
            'answers.needs_hotel' => ['required', 'string', "in:I don't have a hotel,Already booked,I don't need a hotel"],
            'answers.hotel_pick' => ['required_if:answers.needs_hotel,I don\'t have a hotel', 'nullable', 'string', 'max:255'],
            'answers.hotel_location' => ['required_if:answers.needs_hotel,Already booked', 'nullable', 'string', 'max:255'],
            'answers.hotel_shuttle' => ['required_if:answers.needs_hotel,Already booked', 'nullable', 'string', 'in:Yes,No,Not sure'],
            'answers.flying' => ['required', 'string', 'in:Yes,No'],
        ];
    }
}
