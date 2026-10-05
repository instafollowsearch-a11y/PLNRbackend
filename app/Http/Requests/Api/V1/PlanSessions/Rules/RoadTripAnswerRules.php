<?php

namespace App\Http\Requests\Api\V1\PlanSessions\Rules;

class RoadTripAnswerRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'answers.start_location' => ['required', 'string', 'max:255'],
            'answers.end_location' => ['required', 'string', 'max:255'],
            'answers.departure_date' => ['required', 'string', 'max:255'],
            'answers.arrival_date' => ['required', 'string', 'max:255'],
            'answers.departure_time' => ['required', 'string', 'max:255'],
            'answers.car_type' => ['required', 'string', 'max:255'],
            'answers.stop_preference' => ['required', 'string', 'in:straight,with_stops'],
            'answers.stop_interests' => ['required_if:answers.stop_preference,with_stops', 'nullable', 'string', 'min:3'],
            'answers.interests' => ['required', 'string', 'min:3'],
            'answers.food_preferences' => ['required', 'string', 'min:3'],
            'answers.group_size' => ['required', 'integer', 'min:1', 'max:20'],
            'answers.needs_hotel' => ['required', 'string', "in:I need a hotel,Already booked,I don't need a hotel"],
            'answers.hotel_pick' => ['required_if:answers.needs_hotel,I need a hotel', 'nullable', 'string', 'max:255'],
            'answers.hotel_location' => ['required_if:answers.needs_hotel,Already booked', 'nullable', 'string', 'max:255'],
            'answers.hotel_shuttle' => ['required_if:answers.needs_hotel,Already booked', 'nullable', 'string', 'in:Yes,No,Not sure'],
        ];
    }
}
