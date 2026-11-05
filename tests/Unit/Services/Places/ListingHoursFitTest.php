<?php

namespace Tests\Unit\Services\Places;

use App\Support\Places\ListingHoursFit;
use PHPUnit\Framework\TestCase;

class ListingHoursFitTest extends TestCase
{
    public function test_office_hours_do_not_cover_a_late_night_stop(): void
    {
        $this->assertFalse(ListingHoursFit::coversWindow(
            'Thursday: 9:00 AM – 5:00 PM',
            '10:30 PM – 1:00 AM',
        ));
        $this->assertTrue(ListingHoursFit::windowPastClosing(
            '10:30 PM – 1:00 AM',
            'Thursday: 9:00 AM – 5:00 PM',
        ));
    }

    public function test_nightclub_hours_cover_a_late_stop(): void
    {
        $this->assertTrue(ListingHoursFit::coversWindow(
            'Thursday: 10:00 PM – 3:00 AM',
            '10:30 PM – 1:00 AM',
        ));
    }

    public function test_unknown_hours_do_not_block_a_match(): void
    {
        $this->assertTrue(ListingHoursFit::coversWindow(null, '10:30 PM – 1:00 AM'));
        $this->assertTrue(ListingHoursFit::coversWindow('Thursday: 9:00 AM – 5:00 PM', ''));
    }

    public function test_closed_today_does_not_cover_a_visit(): void
    {
        $this->assertFalse(ListingHoursFit::coversWindow('Thursday: Closed', '10:30 PM – 1:00 AM'));
    }
}
