<?php

namespace Tests\Unit\Mail;

use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class PlanTypeMailLayoutTest extends TestCase
{
    public function test_every_plan_type_uses_its_accent_on_the_library_button(): void
    {
        $css = file_get_contents(resource_path('views/vendor/mail/html/themes/plnr.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('#F7F4F0', $css);
        $this->assertStringContainsString('#1A1A1A', $css);

        foreach (['night_out', 'date_night', 'vacation', 'road_trip', 'weekend', 'share'] as $slug) {
            $theme = PlanTypeMailTheme::for($slug);

            $html = Blade::render(<<<'BLADE'
@component('mail::message')
@component('mail::button', ['url' => 'https://myplnr.app/plans', 'color' => $theme['slug']])
View your plans
@endcomponent
Sample plan
@endcomponent
BLADE, ['theme' => $theme]);

            $inlined = (new CssToInlineStyles)->convert($html, $css);

            $this->assertStringContainsString('button-'.$theme['slug'], $html, $slug);
            $this->assertStringContainsString('View your plans', $inlined, $slug);
            $this->assertStringContainsString('https://myplnr.app/plans', $inlined, $slug);
            $this->assertStringContainsString($theme['accent'], $inlined, $slug);
            $this->assertStringContainsString('PLNR', $html, $slug);
            $this->assertStringNotContainsString('data-motif', $html, $slug);
            $this->assertStringNotContainsString('margin-top:-28px', $html, $slug);
            $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html, $slug);
            $this->assertDoesNotMatchRegularExpression('/height="(160|180|200)"/', $html, $slug);
        }
    }
}
