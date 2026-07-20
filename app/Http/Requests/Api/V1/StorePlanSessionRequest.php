<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\PlanSessions\Rules\DateNightAnswerRules;
use App\Http\Requests\Api\V1\PlanSessions\Rules\NightOutAnswerRules;
use App\Http\Requests\Api\V1\PlanSessions\Rules\RoadTripAnswerRules;
use App\Http\Requests\Api\V1\PlanSessions\Rules\VacationAnswerRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePlanSessionRequest extends FormRequest
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
            'plan_type' => ['required', 'string', 'in:night_out,date_night,vacation,road_trip'],
            'answers' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $planType = $this->string('plan_type')->toString();

            $answerRules = match ($planType) {
                'night_out' => NightOutAnswerRules::rules(),
                'date_night' => DateNightAnswerRules::rules(),
                'vacation' => VacationAnswerRules::rules(),
                'road_trip' => RoadTripAnswerRules::rules(),
                default => [],
            };

            $nestedValidator = validator($this->all(), $answerRules);

            if ($nestedValidator->fails()) {
                foreach ($nestedValidator->errors()->messages() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }

    public function resolvedCity(): ?string
    {
        $answers = $this->input('answers', []);

        return match ($this->string('plan_type')->toString()) {
            'vacation' => $answers['destination'] ?? null,
            'road_trip' => $answers['start_location'] ?? null,
            default => $answers['city'] ?? null,
        };
    }
}
