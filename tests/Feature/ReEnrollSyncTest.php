<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingReEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReEnrollSyncTest extends TestCase
{
    use RefreshDatabase;

    private PendingReEnrollment $pending;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 24, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        $this->pending = PendingReEnrollment::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id,
            'source_family_remote_id' => 502, 'family_name' => 'Ana Lim Reyes',
            'members' => [['evacuee_id' => 1804, 'full_name' => 'Ana Lim Reyes', 'sex' => 'female']]]);
    }

    public function test_it_is_sent_with_its_client_ref_and_marked_sent(): void
    {
        Http::fake(['*/evacuation-centers/7/re-enroll' => Http::response(['data' => ['family' => ['id' => 900], 'members_added' => 1]], 201)]);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);

        Http::assertSent(fn ($r) => $r['source_family_id'] === 502 && $r['client_ref'] === $this->pending->client_ref
            && $r['members'] === [['evacuee_id' => 1804]]);
        $this->assertSame(900, (int) $this->pending->fresh()->remote_family_id);
        $this->assertNotNull($this->pending->fresh()->synced_at);
    }

    public function test_a_possible_duplicate_asks_and_the_answer_is_sent_next_time(): void
    {
        Http::fakeSequence('*/evacuation-centers/7/re-enroll')
            ->push(['success' => false, 'message' => 'A family that may be the same one is already in this event. Say whether it is the same family or a different one.',
                'errors' => null, 'data' => ['possible_duplicates' => [['family_id' => 505, 'name' => 'Juan Dela Cruz', 'member_count' => 1,
                    'evacuation_center' => ['id' => 42, 'name' => 'Abella ALS'], 'matched_on' => ['name']]]]], 409)
            ->push(['data' => ['family' => ['id' => 505], 'linked_existing_family' => true]], 201);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'decision']);
        $this->get(route('sync.index'))->assertSee('Juan Dela Cruz')->assertSee('Same family, link it')->assertSee('Different family, add as new');

        $this->post(route('sync.decide', $this->pending), ['decision' => 'link', 'family_id' => 999])->assertSessionHasErrors('family_id');
        $this->post(route('sync.decide', $this->pending), ['decision' => 'link', 'family_id' => 505])->assertSessionHasNoErrors();

        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);
        $last = Http::recorded()->last()[0];
        $this->assertSame('link', $last['duplicate_decision']);
        $this->assertSame(505, $last['link_family_id']);
        $this->assertSame($this->pending->client_ref, $last['client_ref']);
    }

    public function test_it_can_be_removed_before_it_is_sent(): void
    {
        $this->delete(route('sync.forget-returning', $this->pending))->assertRedirect();
        $this->assertSame(0, PendingReEnrollment::count());
    }
}
