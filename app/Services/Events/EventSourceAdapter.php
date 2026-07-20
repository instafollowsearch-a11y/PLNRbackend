<?php

namespace App\Services\Events;

interface EventSourceAdapter
{
    public function source(): string;

    /**
     * @return list<NormalizedEvent>
     */
    public function fetch(string $city): array;
}
