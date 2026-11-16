<?php

namespace Tests\Feature;

use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinalCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cswd_reads_each_barangays_final_count_offline(): void
    {
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'City', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'count_submissions.review'],
            'api_token' => 't', 'logged_in_at' => now()]);
        EvacuationEvent::create(['remote_id' => 21, 'name' => 'Typhoon Amang', 'event_type' => 'typhoon', 'status' => 'active']);
        $submission = fn (string $status, int $families, ?string $note = null) => ['id' => 1, 'status' => $status,
            'families' => $families, 'persons' => $families * 4, 'review_note' => $note];
        Http::fake([
            '*/count-submissions/barangays*' => Http::response(['data' => [
                'evacuation_event' => ['id' => 21, 'name' => 'Typhoon Amang', 'status' => 'active'],
                'barangays' => [
                    ['barangay' => ['id' => 1, 'name' => 'Abella'], 'has_official_account' => true, 'computed' => ['families' => 42],
                        'current_submission' => $submission('validated', 42), 'validated_need' => ['families' => 41]],
                    ['barangay' => ['id' => 2, 'name' => 'Allang'], 'has_official_account' => false, 'computed' => ['families' => 2],
                        'current_submission' => null, 'validated_need' => null],
                    ['barangay' => ['id' => 13, 'name' => 'Binatagan'], 'has_official_account' => true, 'computed' => ['families' => 31],
                        'current_submission' => $submission('pending', 30), 'validated_need' => null],
                ],
            ]]),
            '*/count-submissions?*' => Http::response(['data' => ['data' => [], 'meta' => []]]),
        ]);

        $this->post(route('final-counts.refresh'))->assertSessionHasNoErrors();
        Http::fake(fn () => throw new ConnectionException('down'));

        $page = $this->get(route('final-counts.index'))->assertOk()
            ->assertSee('Typhoon Amang')->assertSee('Read-only')->assertSee('Validate on the web')
            ->assertSee('No official account')->assertSee('changed since submitted');
        // Waiting first, validated last.
        $page->assertSeeInOrder(['Binatagan', 'Allang', 'Abella']);
    }
}
