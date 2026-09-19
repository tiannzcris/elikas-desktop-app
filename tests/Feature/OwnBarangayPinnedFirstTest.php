<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Part 2: the logged-in staff's own barangay (LocalAuth::
 * barangay_id, a REMOTE id) pinned first in both the EC Board and
 * Registered Families barangay lists, with explanatory text -- applies
 * to both even though their underlying data sources differ (EC Board
 * lists barangays with centers; Registered Families lists barangays with
 * at least one family, or the staff's own with zero).
 */
class OwnBarangayPinnedFirstTest extends TestCase
{
    use RefreshDatabase;

    private function loginWithOwnBarangay(int $ownBarangayRemoteId): void
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'barangay_id' => $ownBarangayRemoteId, 'barangay_name' => null,
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // EC Board barangay list
    // -----------------------------------------------------------------

    public function test_ec_board_pins_the_staffs_own_barangay_first_with_a_badge(): void
    {
        $this->loginWithOwnBarangay(2);
        $a = Barangay::create(['remote_id' => 1, 'name' => 'Abella']);
        $own = Barangay::create(['remote_id' => 2, 'name' => 'Zamora']); // alphabetically last on purpose
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center A', 'status' => 'active']);
        EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 2, 'name' => 'Center Z', 'status' => 'active']);

        $page = $this->get(route('ec-board.index'));

        $page->assertOk();
        $content = $page->getContent();
        $ownPos = strpos($content, 'Zamora');
        $otherPos = strpos($content, 'Abella');
        $this->assertNotFalse($ownPos);
        $this->assertNotFalse($otherPos);
        $this->assertTrue($ownPos < $otherPos, 'own barangay (Zamora) must appear before Abella despite alphabetical order');
        $page->assertSee('Your barangay');
        $page->assertSee('Your barangay is shown first', false);
    }

    public function test_ec_board_shows_the_staffs_own_barangay_even_with_zero_cached_centers(): void
    {
        $this->loginWithOwnBarangay(9);
        Barangay::create(['remote_id' => 9, 'name' => 'No Centers Yet']);
        $other = Barangay::create(['remote_id' => 1, 'name' => 'Has A Center']);
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center A', 'status' => 'active']);

        $page = $this->get(route('ec-board.index'));

        $page->assertOk();
        $page->assertSee('No Centers Yet');
        $page->assertSee('0 centers');
    }

    public function test_ec_board_shows_no_badge_or_pinning_for_a_staff_account_with_no_barangay(): void
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Admin', 'email' => 'a@example.com', 'role' => 'administrator',
            'barangay_id' => null, 'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        Barangay::create(['remote_id' => 1, 'name' => 'Abella']);
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center A', 'status' => 'active']);

        $page = $this->get(route('ec-board.index'));

        $page->assertOk();
        // The explanatory paragraph always mentions "Your barangay is
        // shown first" -- checking for that exact phrase alone would be
        // a false positive here, so this targets the per-row BADGE
        // markup specifically (text-[10px], a size used nowhere else on
        // this page), confirming no row is actually flagged/pinned.
        $page->assertDontSee('text-[10px]', false);
    }

    // -----------------------------------------------------------------
    // Registered Families barangay list
    // -----------------------------------------------------------------

    public function test_registered_families_pins_the_staffs_own_barangay_first_with_a_badge(): void
    {
        $this->loginWithOwnBarangay(2);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $a = Barangay::create(['remote_id' => 1, 'name' => 'Abella']);
        $own = Barangay::create(['remote_id' => 2, 'name' => 'Zamora']);
        Family::create(['barangay_id' => $a->id, 'evacuation_event_id' => $event->id, 'displacement_type' => 'outside_center']);
        Family::create(['barangay_id' => $own->id, 'evacuation_event_id' => $event->id, 'displacement_type' => 'outside_center']);

        $page = $this->get(route('families.index'));

        $page->assertOk();
        $content = $page->getContent();
        $ownPos = strpos($content, 'Zamora');
        $otherPos = strpos($content, 'Abella');
        $this->assertNotFalse($ownPos);
        $this->assertNotFalse($otherPos);
        $this->assertTrue($ownPos < $otherPos, 'own barangay (Zamora) must appear before Abella despite alphabetical order');
        $page->assertSee('Your barangay');
        $page->assertSee('Your barangay is shown first', false);
    }

    /**
     * Confirmed real gap this fix closes: barangaySummary() used to be
     * built ONLY from Family::groupBy('barangay_id') -- a barangay with
     * zero registrations so far never appeared at all, meaning a staff
     * account whose own barangay had nothing registered yet would never
     * see it in this list, hiding the exact place they're most likely to
     * start registering from.
     */
    public function test_registered_families_shows_the_staffs_own_barangay_even_with_zero_registrations(): void
    {
        $this->loginWithOwnBarangay(9);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        Barangay::create(['remote_id' => 9, 'name' => 'Nothing Registered Yet']);
        $other = Barangay::create(['remote_id' => 1, 'name' => 'Has Families']);
        Family::create(['barangay_id' => $other->id, 'evacuation_event_id' => $event->id, 'displacement_type' => 'outside_center']);

        $page = $this->get(route('families.index'));

        $page->assertOk();
        $page->assertSee('Nothing Registered Yet');
        $page->assertSee('0 families');
    }
}
