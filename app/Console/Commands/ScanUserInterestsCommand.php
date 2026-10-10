<?php

namespace App\Console\Commands;

use App\Services\Interests\ScanUserInterests;
use Illuminate\Console\Command;

class ScanUserInterestsCommand extends Command
{
    protected $signature = 'interests:scan';

    protected $description = 'Match saved user interests to upcoming local events';

    public function handle(ScanUserInterests $scan): int
    {
        $result = $scan->run();

        $this->info("Checked {$result->users_checked} accounts. Kept {$result->matches_kept} matches. Skipped {$result->users_skipped}.");

        return self::SUCCESS;
    }
}
