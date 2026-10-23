<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\PlanSessions\Rules\DateNightAnswerRules;
use App\Http\Requests\Api\V1\PlanSessions\Rules\NightOutAnswerRules;
use App\Http\Requests\Api\V1\PlanSessions\Rules\RoadTripAnswerRules;
use App\Http\Requests\Api\V1\PlanSessions\Rules\VacationAnswerRules;
use App\Models\PlanSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePlanSessionAnswersRequest extends FormRequest
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
            'answers' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $planType = $this->resolvedPlanTypeSlug();

            if ($planType === null) {
                $validator->errors()->add('answers', 'Unable to resolve plan type for this session.');

                return;
            }

            $answerRules = match ($planType) {
                'night_out' => NightOutAnswerRules::rules(),
                'date_night' => DateNightAnswerRules::rules(),
                'vacation' => VacationAnswerRules::rules(),
                'road_trip' => RoadTripAnswerRules::rules(),
                default => [],
            };

            $nestedValidator = validator(
                [
                    'plan_type' => $planType,
                    'answers' => $this->input('answers'),
                ],
                $answerRules,
            );

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
        $planType = $this->resolvedPlanTypeSlug() ?? '';

        return match ($planType) {
            'vacation' => $answers['destination'] ?? null,
            'road_trip' => $answers['start_location'] ?? null,
            default => $answers['city'] ?? null,
        };
    }

    public function resolvedPlanTypeSlug(): ?string
    {
        $session = $this->route('planSession');

        if (! $session instanceof PlanSession) {
            return null;
        }

        $session->loadMissing('planType');

        $slug = $session->planType?->slug;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
