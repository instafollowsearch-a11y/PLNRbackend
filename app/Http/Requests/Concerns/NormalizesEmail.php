<?php

namespace App\Http\Requests\Concerns;

use App\Support\Email;

trait NormalizesEmail
{
    protected function normalizeEmailInput(string $key = 'email'): void
    {
        if (! $this->exists($key)) {
            return;
        }

        $value = $this->input($key);

        if (! is_string($value)) {
            return;
        }

        $this->merge([$key => Email::normalize($value)]);
    }
}
