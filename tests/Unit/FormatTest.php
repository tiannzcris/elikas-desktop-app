<?php

namespace Tests\Unit;

use App\Support\EventLock;
use App\Support\FacilityRequestStatus;
use App\Support\Format;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dates_read_like_nov_19_1_44_am_in_philippine_time(): void
    {
        Carbon::setTestNow('2026-11-23 04:00:00');
        $this->assertSame('Nov 19, 1:44 AM', Format::when('2026-11-18T17:44:00Z'));
        $this->assertSame('Dec 30, 2025, 9:05 PM', Format::when('2025-12-30T13:05:00Z'));
        $this->assertSame('', Format::when(null));
        $this->assertSame('Nov 19', Format::day('2026-11-19'));
    }

    public function test_durations_are_words(): void
    {
        $this->assertSame('less than a minute', Format::duration(0));
        $this->assertSame('1 minute', Format::duration(1));
        $this->assertSame('45 minutes', Format::duration(45));
        $this->assertSame('1 hour', Format::duration(60));
        $this->assertSame('2 hours 30 minutes', Format::duration(150));
        $this->assertSame('5 hours', Format::duration(305));
        $this->assertSame('1 day', Format::duration(24 * 60));
        $this->assertSame('3 days', Format::duration(3 * 24 * 60 + 200));
    }

    public function test_numbers_have_thousands_separators(): void
    {
        $this->assertSame('1,234', Format::number(1234));
        $this->assertSame('1,234,567', Format::number('1234567'));
    }

    public function test_the_servers_lock_answers_are_recognised(): void
    {
        $this->assertSame(EventLock::MONITORING, EventLock::kindOf('"Incoming Typhoon" is being monitored. Transfers opens when CSWD starts the evacuation.'));
        $this->assertSame(EventLock::CLOSED, EventLock::kindOf('"Opong" is closed. A closed event is read-only.'));
        $this->assertSame(EventLock::NO_OPEN_EVENT, EventLock::kindOf('No open event yet. CSWDO opens an event when a threat is announced.'));
        $this->assertNull(EventLock::kindOf('The given data was invalid.'));
        $this->assertSame('"Incoming Typhoon" is being monitored. Final counts opens when CSWD starts the evacuation.',
            EventLock::monitoringMessage('Incoming Typhoon', EventLock::FINAL_COUNTS));
    }

    public function test_facility_requests_read_received_also_for_the_old_fulfilled(): void
    {
        $this->assertSame('Received', FacilityRequestStatus::of('received')[0]);
        $this->assertSame('Received', FacilityRequestStatus::of('fulfilled')[0]);
        $this->assertSame('Sent', FacilityRequestStatus::of('sent')[0]);
        $this->assertSame('Approved 6 of 10 pcs', FacilityRequestStatus::approvedOf(['quantity' => 10, 'quantity_approved' => 6, 'unit' => 'pcs']));
        $this->assertNull(FacilityRequestStatus::approvedOf(['quantity' => 10, 'quantity_approved' => 10]));
    }
}
