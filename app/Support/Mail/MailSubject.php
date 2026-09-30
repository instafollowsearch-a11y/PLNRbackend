<?php

namespace App\Support\Mail;

use Illuminate\Support\Carbon;

final class MailSubject
{
    public static function stamp(string $subject): string
    {
        $stamp = Carbon::now()
            ->timezone((string) config('app.timezone'))
            ->format('M j, Y, g:i:s A');

        return $subject.' · '.$stamp;
    }
}
