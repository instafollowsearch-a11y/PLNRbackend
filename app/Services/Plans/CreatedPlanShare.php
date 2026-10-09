<?php

namespace App\Services\Plans;

use App\Models\PlanShare;

class CreatedPlanShare
{
    public function __construct(
        public readonly PlanShare $share,
        public readonly bool $smsSent,
        public readonly ?string $smsNotice,
    ) {}
}
