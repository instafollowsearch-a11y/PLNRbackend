<?php

namespace Tests\Unit\Mail;

use App\Mail\ResetPasswordMail;
use App\Support\Mail\MailSubject;
use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ResetPasswordMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_the_plnr_brand_and_a_unique_subject(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 17:54:07', 'UTC'));

        $mail = new ResetPasswordMail(
            'dterefe0@gmail.com',
            'https://myplnr.app/reset-password?token=abc&email=dterefe0%40gmail.com',
        );
        $html = $mail->render();
        $theme = PlanTypeMailTheme::brand();

        $this->assertSame(MailSubject::stamp('Reset your password'), $mail->envelope()->subject);
        $this->assertSame('dterefe0@gmail.com', $mail->envelope()->to[0]->address);
        $this->assertStringContainsString('PLNR', $html);
        $this->assertStringContainsString('Choose a new password', $html);
        $this->assertStringContainsString('dterefe0@gmail.com', $html);
        $this->assertStringContainsString($theme['accent'], $html);
        $this->assertStringContainsString('alt="PLNR"', $html);
        $this->assertStringContainsString('width="120"', $html);
        $this->assertStringContainsString('button-account', $html);
        $this->assertStringContainsString('#F7F4F0', $html);
        $this->assertStringNotContainsString('data-motif', $html);
        $this->assertStringContainsString('If you did not ask for this, you can ignore this email.', $html);
        $this->assertStringNotContainsString('Enjoy your plans.', $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);

        Carbon::setTestNow();
    }
}
