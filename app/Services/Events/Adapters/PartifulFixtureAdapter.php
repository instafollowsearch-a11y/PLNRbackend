<?php

namespace App\Services\Events\Adapters;

class PartifulFixtureAdapter extends AbstractSubdirectoryFixtureAdapter
{
    public function source(): string
    {
        return 'partiful';
    }

    protected function subdirectory(): string
    {
        return 'partiful';
    }
}
