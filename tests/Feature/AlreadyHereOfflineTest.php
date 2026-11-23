<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlreadyHereOfflineTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): array
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);

        return [$event, $center];
    }

    public function test_the_list_is_kept_and_used_when_offline(): void
    {
        [$event, $center] = $this->seedBase();
        Http::fake(['*/evacuation-centers/7/families*' => Http::response(['data' => [
            ['id' => 50, 'name' => 'Reyes', 'head_of_family' => ['id' => 1, 'full_name' => 'Ana Reyes'], 'here_count' => 3],
        ]])]);
        $this->getJson(route('evacuation-centers.households-refresh', ['center' => $center, 'event' => $event->id]))
            ->assertOk()->assertJsonPath('cached', false)->assertJsonPath('households.0.value', 'remote-50');

        Http::fake(fn () => throw new ConnectionException('down'));
        $this->getJson(route('evacuation-centers.households-refresh', ['center' => $center, 'event' => $event->id]))
            ->assertOk()->assertJsonPath('cached', true)->assertJsonPath('households.0.label', 'Reyes');
    }

    public function test_refresh_prefetches_every_open_center_and_event(): void
    {
        $this->seedBase();
        Http::fake([
            '*/auth/me' => Http::response(['data' => []]),
            '*/barangays' => Http::response(['data' => [['id' => 4, 'name' => 'Bacong']]]),
            '*/evacuation-events' => Http::response(['data' => [['id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']]]),
            '*/evacuation-centers?detail=1' => Http::response(['data' => [['id' => 7, 'barangay_id' => 4, 'name' => 'Gym', 'status' => 'active']]]),
            '*/puroks*' => Http::response(['data' => []]),
            '*/families-to-evacuate*' => Http::response(['data' => ['families' => []]]),
            '*/count-submissions*' => Http::response(['data' => []]),
            '*/box-relief/*' => Http::response(['data' => []]),
            '*/dashboard/barangay*' => Http::response(['data' => []]),
            '*/alerts*' => Http::response(['data' => ['data' => []]]),
            '*/transfer-requests*' => Http::response(['data' => ['requests' => [], 'counts' => ['pending' => 0]]]),
            '*/private-house-families*' => Http::response(['data' => ['barangays' => [], 'families' => []]]),
            '*/evacuation-centers/7/families*' => Http::response(['data' => [['id' => 50, 'name' => 'Reyes']]]),
            '*/returning-families*' => Http::response(['data' => ['past_events' => [], 'families' => []]]),
        ]);

        $this->post(route('reference-data.refresh'))->assertSessionHasNoErrors();

        $this->assertSame(50, CenterFamilySnapshot::first()->families[0]['id']);
    }
}
