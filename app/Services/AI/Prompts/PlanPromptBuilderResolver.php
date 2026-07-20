<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use InvalidArgumentException;

class PlanPromptBuilderResolver
{
    /** @var array<string, PlanPromptBuilder> */
    private array $builders;

    public function __construct()
    {
        $instances = [
            new NightOutPromptBuilder,
            new DateNightPromptBuilder,
            new VacationPromptBuilder,
            new RoadTripPromptBuilder,
        ];

        $this->builders = [];

        foreach ($instances as $builder) {
            $this->builders[$builder->slug()] = $builder;
        }
    }

    public function resolve(PlanSession $session): PlanPromptBuilder
    {
        $session->loadMissing('planType');

        $slug = $session->planType?->slug;

        if ($slug === null || ! isset($this->builders[$slug])) {
            throw new InvalidArgumentException('Unsupported plan type for prompts.');
        }

        return $this->builders[$slug];
    }
}
