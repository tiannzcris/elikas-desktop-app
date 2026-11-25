<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingReEnrollment;
use App\Models\ReturningFamilySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReturningFamiliesTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationCenter $center;

    private EvacuationEvent $event;

    private function reyes(): array
    {
        return [
            'id' => 502, 'name' => 'Ana Lim Reyes', 'head_name' => 'Ana Lim Reyes', 'purok' => ['id' => 7, 'name' => 'Purok 3'],
            'last_center' => ['id' => 42, 'name' => 'Abella ALS'], 're_enrolled_family_id' => null, 'unnamed_member_count' => 1,
            'members' => [
                ['id' => 1804, 'full_name' => 'Ana Lim Reyes', 'sex' => 'female', 'age' => 41, 'age_bracket' => 'adult',
                    'needs_age_bracket' => false, 'is_head' => true, 'was_pregnant' => true, 'was_lactating' => false, 'checked_in' => false],
                ['id' => 1810, 'full_name' => 'Carla Reyes', 'sex' => 'female', 'age' => null, 'age_bracket' => null,
                    'needs_age_bracket' => true, 'is_head' => false, 'was_pregnant' => false, 'was_lactating' => false, 'checked_in' => false],
                ['id' => 1811, 'full_name' => 'Ben Reyes', 'sex' => 'male', 'age' => 11, 'age_bracket' => 'school_age',
                    'needs_age_bracket' => false, 'is_head' => false, 'was_pregnant' => false, 'was_lactating' => false, 'checked_in' => true],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'permissions' => ['operations.access', 'families.re_enroll', 'puroks.view'],
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 24, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
    }

    private function snapshot(): void
    {
        ReturningFamilySnapshot::create(['evacuation_event_id' => $this->event->id,
            'past_events' => [['id' => 23, 'name' => 'Past typhoon', 'family_count' => 1]],
            'families_by_event' => ['23' => [$this->reyes()]], 'fetched_at' => now()]);
    }

    public function test_refresh_keeps_past_events_and_their_families(): void
    {
        Http::fake([
            '*/auth/me' => Http::response(['data' => []]),
            '*/barangays' => Http::response(['data' => [['id' => 4, 'name' => 'Bacong']]]),
            '*/evacuation-events' => Http::response(['data' => [['id' => 24, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']]]),
            '*/evacuation-centers?detail=1*' => Http::response(['data' => [['id' => 7, 'barangay_id' => 4, 'name' => 'Gym', 'status' => 'active']]]),
            '*/puroks*' => Http::response(['data' => []]),
            '*/private-house-families*' => Http::response(['data' => ['barangays' => [], 'families' => []]]),
            '*/evacuation-centers/7/families*' => Http::response(['data' => []]),
            '*/returning-families*' => function ($request) {
                return str_contains($request->url(), 'source_event_id=23')
                    ? Http::response(['data' => ['families' => [$this->reyes()]]])
                    : Http::response(['data' => ['past_events' => [['id' => 23, 'name' => 'Past typhoon', 'family_count' => 1]], 'families' => []]]);
            },
        ]);

        $this->post(route('reference-data.refresh'))->assertSessionHasNoErrors();

        $snapshot = ReturningFamilySnapshot::first();
        $this->assertSame('Past typhoon', $snapshot->past_events[0]['name']);
        $this->assertSame(502, $snapshot->familiesOf(23)[0]['id']);
    }

    public function test_the_list_works_offline_and_ticking_queues_a_re_enrollment(): void
    {
        $this->snapshot();

        $this->get(route('returning-families.index', ['center' => $this->center, 'event' => $this->event->id]))
            ->assertOk()->assertSee('Ana Lim Reyes')->assertSee('Already checked in')->assertSee('Pregnant now');

        $this->post(route('returning-families.store', $this->center), [
            'evacuation_event_id' => $this->event->id, 'source_event_id' => 23, 'source_family_id' => 502,
            'members' => [1804 => ['ticked' => '1', 'is_pregnant' => '1'], 1810 => ['ticked' => '1', 'age_bracket' => 'teenage']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $pending = PendingReEnrollment::first();
        $this->assertSame([
            'evacuation_event_id' => 24, 'source_family_id' => 502,
            'members' => [['evacuee_id' => 1804, 'is_pregnant' => true], ['evacuee_id' => 1810, 'age_bracket' => 'teenage']],
        ], $pending->toSyncPayload());

        $this->get(route('evacuation-centers.ec-board', ['center' => $this->center, 'event' => $this->event->id]))
            ->assertSee('data-pending-returning', false)->assertSee('Returning family');
        $this->get(route('returning-families.index', ['center' => $this->center, 'event' => $this->event->id]))
            ->assertSee('Waiting to send');
    }

    public function test_a_missing_age_group_and_a_checked_in_person_are_refused(): void
    {
        $this->snapshot();
        $store = fn (array $members) => $this->post(route('returning-families.store', $this->center), [
            'evacuation_event_id' => $this->event->id, 'source_event_id' => 23, 'source_family_id' => 502, 'members' => $members,
        ]);

        $store([1810 => ['ticked' => '1']])->assertSessionHasErrors('members.1810.age_bracket');
        $store([1811 => ['ticked' => '1']])->assertSessionHasErrors('members');
        $store([])->assertSessionHasErrors('members');
        $this->assertSame(0, PendingReEnrollment::count());
    }
}
