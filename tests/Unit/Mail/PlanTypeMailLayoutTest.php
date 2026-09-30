<?php

namespace Tests\Unit\Mail;

use App\Support\Mail\PlanTypeMailTheme;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PlanTypeMailLayoutTest extends TestCase
{
    public function test_every_plan_type_uses_the_short_skin_behind_the_card(): void
    {
        foreach (['night_out', 'date_night', 'vacation', 'road_trip', 'weekend', 'share'] as $slug) {
            $theme = PlanTypeMailTheme::for($slug);

            $html = Blade::render(<<<'BLADE'
@component('mail.layouts.plnr', ['theme' => $theme, 'title' => 'PLNR', 'heroEyebrow' => 'Your itinerary', 'heroTitle' => 'Sample plan'])
Body
@endcomponent
BLADE, ['theme' => $theme]);

            $this->assertStringContainsString('margin-top:-28px', $html, $slug);
            $this->assertStringContainsString('data-motif="'.$theme['motif'].'"', $html, $slug);
            $this->assertDoesNotMatchRegularExpression('/height="(160|180|200)"/', $html, $slug);
        }
    }
}
