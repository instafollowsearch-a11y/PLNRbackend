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
        config([
            'services.pro.app_store_url' => 'https://apps.apple.com/app/plnr',
            'services.pro.play_store_url' => 'https://play.google.com/store/apps/details?id=app.plnr',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-30 17:54:07', 'UTC'));

        $mail = new ResetPasswordMail(
            'dterefe0@gmail.com',
            '482913',
        );
        $html = $mail->render();
        $theme = PlanTypeMailTheme::brand();

        $this->assertSame(MailSubject::stamp('Reset your password'), $mail->envelope()->subject);
        $this->assertSame('dterefe0@gmail.com', $mail->envelope()->to[0]->address);
        $this->assertStringContainsString('PLNR', $html);
        $this->assertStringContainsString('Choose a new password', $html);
        $this->assertStringContainsString('dterefe0@gmail.com', $html);
        $this->assertStringContainsString('482913', $html);
        $this->assertStringContainsString('Enter this code in the PLNR app', $html);
        $this->assertStringNotContainsString('/reset-password?', $html);
        $this->assertStringContainsString($theme['accent'], $html);
        $this->assertStringContainsString('alt="PLNR"', $html);
        $this->assertStringContainsString('width="120"', $html);
        $this->assertStringContainsString('#F7F4F0', $html);
        $this->assertStringNotContainsString('data-motif', $html);
        $this->assertStringContainsString('If you did not ask for this, you can ignore this email.', $html);
        $this->assertStringNotContainsString('Enjoy your plans.', $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);
        $this->assertStringContainsString('https://apps.apple.com/app/plnr', $html);
        $this->assertStringContainsString('https://play.google.com/store/apps/details?id=app.plnr', $html);
        $this->assertStringContainsString('href="https://apps.apple.com/app/plnr"', $html);
        $this->assertStringContainsString('href="https://play.google.com/store/apps/details?id=app.plnr"', $html);
        $this->assertStringContainsString('Download on the App Store', $html);
        $this->assertStringContainsString('Get it on Google Play', $html);
        $this->assertStringContainsString('class="store-badge"', $html);
        $this->assertStringContainsString('height="40"', $html);
        $this->assertStringContainsString('.store-badge', $html);
        $this->assertStringContainsString('width: auto !important', $html);
        $this->assertSame(2, substr_count($html, 'class="store-badge"'));
        $this->assertStringNotContainsString('class="button button-store"', $html);

        Carbon::setTestNow();
    }

    public function test_store_buttons_show_even_when_the_urls_are_not_set(): void
    {
        config([
            'services.pro.app_store_url' => null,
            'services.pro.play_store_url' => null,
        ]);

        $html = (new ResetPasswordMail('dterefe0@gmail.com', '482913'))->render();

        $this->assertStringContainsString('Download on the App Store', $html);
        $this->assertStringContainsString('Get it on Google Play', $html);
        $this->assertStringContainsString('store-badge', $html);
        $this->assertStringNotContainsString('href="https://apps.apple.com', $html);
        $this->assertStringNotContainsString('href="https://play.google.com', $html);
    }
}
