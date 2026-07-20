<?php

namespace App\Services\Events\Adapters;

class PoshFixtureAdapter extends AbstractSubdirectoryFixtureAdapter
{
    public function source(): string
    {
        return 'posh';
    }

    protected function subdirectory(): string
    {
        return 'posh';
    }
}
