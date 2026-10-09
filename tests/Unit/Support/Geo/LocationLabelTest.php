<?php

namespace Tests\Unit\Support\Geo;

use App\Support\Geo\LocationLabel;
use Tests\TestCase;

class LocationLabelTest extends TestCase
{
    public function test_a_city_label_stays_a_city(): void
    {
        $this->assertSame('Austin', LocationLabel::cityName('Austin, Texas'));
        $this->assertSame('Austin', LocationLabel::cityName('Austin'));
        $this->assertSame('Austin, Texas', LocationLabel::locality('Austin, Texas'));
        $this->assertSame('Austin, Texas', LocationLabel::eventCity('Austin, Texas'));
    }

    public function test_a_street_address_keeps_the_city_for_lookups(): void
    {
        $label = '840 Westview Drive Southwest, Atlanta, Georgia';

        $this->assertSame('Atlanta', LocationLabel::cityName($label));
        $this->assertSame('Atlanta, Georgia', LocationLabel::locality($label));
        $this->assertSame('Atlanta', LocationLabel::eventCity($label));
    }

    public function test_a_road_without_a_number_is_still_a_street(): void
    {
        $this->assertSame('Atlanta', LocationLabel::cityName('Westview Drive, Atlanta, Georgia'));
    }
}
