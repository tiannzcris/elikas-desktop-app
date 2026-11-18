<?php

namespace Tests\Feature;

use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use App\Services\Transfers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_demo_request_with_no_requested_by_shows_the_sending_barangay(): void
    {
        $this->assertSame('Cabarian', Transfers::requestedBy(['requested_by' => null, 'from_barangay' => ['name' => 'Cabarian']]));
        $this->assertSame('Ana Cruz', Transfers::requestedBy(['requested_by' => ['name' => 'Ana Cruz'], 'from_barangay' => ['name' => 'Cabarian']]));

        LocalAuth::create(['remote_user_id' => 2, 'name' => 'C', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'transfers.manage'], 'api_token' => 't', 'logged_in_at' => now()]);
        $event = EvacuationEvent::create(['remote_id' => 85, 'name' => 'DEMO - Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active', 'is_demo' => true]);
        ServerSnapshot::keep('transfers.'.$event->id, ['counts' => ['pending' => 1], 'requests' => [[
            'id' => 5, 'status' => 'pending', 'direction' => 'all', 'from_center' => ['name' => 'Cabarian Hall'], 'to_center' => ['name' => 'DA Building'],
            'from_barangay' => ['id' => 7, 'name' => 'Cabarian'], 'to_barangay' => ['id' => 49, 'name' => 'Tomolin'], 'families' => 1, 'persons' => 6,
            'family_list' => [['head' => 'Demo Family', 'members' => 6]], 'requested_by' => null, 'requested_at' => '2026-11-18T06:00:00+00:00',
            'waiting_minutes' => 4, 'may_decide' => true,
        ]]]);

        $this->get(route('transfers.index'))->assertOk()->assertSeeInOrder(['Asked by', 'Cabarian · Nov 18, 2:00 PM']);
    }
}
