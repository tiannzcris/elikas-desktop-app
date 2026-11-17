<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CenterApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
    }

    public function test_refresh_keeps_own_usable_centers_with_their_approval_state(): void
    {
        $this->signIn();
        Http::fake([
            '*/auth/me' => Http::response(['data' => []]),
            '*/barangays' => Http::response(['data' => [['id' => 4, 'name' => 'Bacong'], ['id' => 5, 'name' => 'Other']]]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers?detail=1' => Http::response(['data' => [
                ['id' => 1, 'barangay_id' => 4, 'name' => 'Gym', 'status' => 'active', 'approval_status' => 'approved'],
                ['id' => 2, 'barangay_id' => 4, 'name' => 'New Hall', 'status' => 'active', 'approval_status' => 'pending'],
                ['id' => 3, 'barangay_id' => 4, 'name' => 'Refused', 'status' => 'active', 'approval_status' => 'rejected'],
                ['id' => 4, 'barangay_id' => 5, 'name' => 'Elsewhere', 'status' => 'active', 'approval_status' => 'approved'],
            ]]),
        ]);

        $this->post(route('reference-data.refresh'));

        $this->assertEqualsCanonicalizing([1, 2], EvacuationCenter::pluck('remote_id')->all());
        $this->assertTrue(EvacuationCenter::firstWhere('remote_id', 2)->isUnverified());
    }

    public function test_an_unverified_center_is_marked_on_its_board(): void
    {
        $this->signIn();
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $center = EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 4, 'name' => 'New Hall', 'status' => 'active', 'approval_status' => 'pending']);

        $this->get(route('evacuation-centers.ec-board', $center))->assertOk()->assertSee('Unverified');
    }
}
