<?php

namespace App\Services\Events\Adapters;

class GrouponFixtureAdapter extends AbstractSubdirectoryFixtureAdapter
{
    public function source(): string
    {
        return 'groupon';
    }

    protected function subdirectory(): string
    {
        return 'groupon';
    }
}
