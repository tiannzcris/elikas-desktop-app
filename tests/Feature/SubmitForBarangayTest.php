<?php

namespace Tests\Feature;

use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubmitForBarangayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'C', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'count_submissions.review', 'count_submissions.submit_on_behalf'],
            'api_token' => 't', 'logged_in_at' => now()]);
        EvacuationEvent::create(['remote_id' => 21, 'name' => 'Typhoon Amang', 'event_type' => 'typhoon', 'status' => 'active']);
        ServerSnapshot::keep('final_counts', ['evacuation_event' => ['id' => 21, 'name' => 'Typhoon Amang', 'status' => 'active'], 'barangays' => [
            ['barangay' => ['id' => 2, 'name' => 'Allang'], 'has_official_account' => false, 'computed' => ['families' => 2, 'persons' => 4],
                'current_submission' => null, 'validated_need' => null],
            ['barangay' => ['id' => 4, 'name' => 'Bacong'], 'has_official_account' => true, 'computed' => ['families' => 9, 'persons' => 30],
                'current_submission' => null, 'validated_need' => null],
        ]]);
    }

    public function test_only_a_barangay_with_no_official_account_offers_it_with_the_systems_count(): void
    {
        $page = $this->get(route('final-counts.index'))->assertOk();
        $this->assertSame(1, substr_count($page->getContent(), 'data-submit-for-barangay'));
        $page->assertSee('Submit the final count for Allang?')->assertSee("The system's count now: 2 families, 4 people.", false);
    }

    public function test_submitting_sends_it_and_refreshes_the_list(): void
    {
        Http::fake([
            '*/barangays/2/count-submissions' => Http::response(['data' => ['id' => 50, 'submitted_on_behalf' => true]], 201),
            '*/count-submissions/barangays*' => Http::response(['data' => ['barangays' => [
                ['barangay' => ['id' => 2, 'name' => 'Allang'], 'has_official_account' => false, 'computed' => ['families' => 2],
                    'current_submission' => ['status' => 'pending', 'families' => 2, 'persons' => 4, 'submitted_on_behalf' => true]],
            ]]]),
            '*/count-submissions?*' => Http::response(['data' => ['data' => []]]),
        ]);

        $this->from(route('final-counts.index'))->post(route('final-counts.submit-on-behalf'), ['barangay_id' => 2, 'barangay_name' => 'Allang'])
            ->assertRedirect(route('final-counts.index'))
            ->assertSessionHas('status', 'Final count submitted for Allang (submitted by CSWD for the barangay).');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/barangays/2/count-submissions') && $r['evacuation_event_id'] === 21);
        $this->get(route('final-counts.index'))->assertSee('Submitted by CSWD for the barangay')->assertDontSee('data-submit-for-barangay', false);
    }

    public function test_offline_nothing_is_submitted(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        $this->from(route('final-counts.index'))->post(route('final-counts.submit-on-behalf'), ['barangay_id' => 2, 'barangay_name' => 'Allang'])
            ->assertSessionHasErrors(['submit' => 'Needs a connection. Nothing was submitted.']);
    }
}
