<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use App\Models\ServerSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 2.1.0 part 5: the official's final count, late entries, submit online, "Submitted by CSWD on behalf of". */
class BarangayFinalCountTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active']);
    }

    private function copy(array $computed, array $submissions = []): void
    {
        ServerSnapshot::keep('final_count.79', ['computed' => $computed, 'submissions' => $submissions]);
    }

    public function test_the_page_shows_the_count_late_entries_and_checks(): void
    {
        $this->copy([
            'families' => 1234, 'persons' => 4321, 'families_in_centers' => 1200, 'persons_in_centers' => 4200,
            'families_in_private_houses' => 34, 'persons_in_private_houses' => 121,
            'late_entries' => ['since' => '2026-11-17T06:00:00Z', 'families' => 3, 'persons' => 11, 'removed_families' => 0, 'total' => 3],
            'checks' => ['incomplete_families' => 1, 'possible_duplicates' => 2],
        ], [['id' => 41, 'status' => 'validated', 'families' => 1231, 'persons' => 4310, 'submitted_at' => '2026-11-17T06:00:00Z']]);

        $this->get(route('final-count.index'))->assertOk()
            ->assertSee('1,234')->assertSee('4,321')->assertSee('In private houses')
            ->assertSee('3 late entries, not yet in your final count.')
            ->assertSee('1 family has incomplete details')->assertSee('2 possible duplicates')
            ->assertSee('Submit updated count')
            ->assertSee('data-confirm-cancel="Review first"', false)
            ->assertSee('After this, new evacuees are marked as late entries.');
    }

    public function test_a_validated_count_with_nothing_new_has_nothing_to_submit(): void
    {
        $this->copy(['families' => 10, 'persons' => 40, 'late_entries' => ['total' => 0]], [['id' => 41, 'status' => 'validated', 'families' => 10, 'persons' => 40]]);

        $this->get(route('final-count.index'))->assertSee('data-nothing-new', false)->assertDontSee('data-final-count-form', false);
    }

    public function test_a_count_cswd_submitted_on_behalf_says_so(): void
    {
        $this->copy(['families' => 10, 'persons' => 40], [['id' => 42, 'status' => 'validated', 'families' => 10, 'persons' => 40, 'submitted_on_behalf' => true,
            'note' => 'Submitted by CSWD on behalf of Bacong. Reason: The official was unreachable during the typhoon.']]);

        $this->get(route('final-count.index'))->assertSee('data-on-behalf', false)
            ->assertSee('Submitted by CSWD on behalf of Bacong. Reason: The official was unreachable during the typhoon.')
            ->assertSee('You can still submit an updated count.');
    }

    public function test_submitting_goes_to_the_server_and_refreshes(): void
    {
        $this->copy(['families' => 10, 'persons' => 40]);
        Http::fake([
            '*/count-submissions/computed*' => Http::response(['data' => ['families' => 10, 'persons' => 40]]),
            '*/count-submissions?*' => Http::response(['data' => ['data' => [['id' => 43, 'status' => 'pending', 'families' => 10, 'persons' => 40]]]]),
            '*/count-submissions' => Http::response(['message' => 'Final count submitted.', 'data' => ['id' => 43]], 201),
        ]);

        $this->post(route('final-count.submit'), ['note' => 'All centers counted'])->assertSessionHas('status', 'Final count submitted.');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/count-submissions') && $r['evacuation_event_id'] === 79 && $r['note'] === 'All centers counted');
        $this->get(route('final-count.index'))->assertSee('Waiting for CSWD');
    }

    public function test_offline_nothing_is_submitted_and_unsent_records_are_warned_about(): void
    {
        $this->copy(['families' => 10, 'persons' => 40]);
        PendingCheckOut::create(['evacuation_center_id' => \App\Models\EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active'])->id,
            'evacuation_event_id' => $this->event->id, 'family_remote_id' => 5, 'family_label' => 'Reyes', 'status' => 'returned_home']);

        $this->get(route('final-count.index'))->assertSee('1 record on this computer is not sent yet and not in this count.');

        Http::fake(fn () => throw new ConnectionException('down'));
        $this->post(route('final-count.submit'))->assertSessionHasErrors(['submit' => 'Needs a connection. Nothing was submitted.']);
    }

    public function test_final_counts_wait_while_the_event_is_monitored(): void
    {
        $this->event->update(['status' => 'monitoring']);
        $this->copy(['families' => 0, 'persons' => 0]);

        $this->get(route('final-count.index'))->assertSee('data-lock-notice="monitoring"', false)->assertDontSee('data-final-count-form', false);
        $this->post(route('final-count.submit'))->assertSessionHasErrors(['evacuation_event_id' => '"Typhoon Drill" is being monitored. Final counts opens when CSWD starts the evacuation.']);
        Http::assertNothingSent();
    }
}
