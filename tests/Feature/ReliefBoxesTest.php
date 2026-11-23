<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingHandout;
use App\Services\BoxRelief;
use App\Services\LocalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Relief boxes (docs/backend/apps-api-2026-11-24.md 1): priority, Next in line, staying elsewhere, give offline with client_ref. */
class ReliefBoxesTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'Official One', 'email' => 'brgy.tuburan', 'role' => 'barangay_official',
            'barangay_id' => 1, 'barangay_name' => 'Tuburan', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 1, 'name' => 'Tuburan']);
        $this->event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon Ofel', 'event_type' => 'typhoon', 'status' => 'active']);
    }

    /** The family list of the doc's example (1.1), with $received boxes. */
    private function families(int $received = 3): array
    {
        $fam = fn (int $id, string $name, ?string $head, int $members, ?int $tier, ?string $label, int $order, int $higher, bool $next, array $extra = []) => $extra + [
            'id' => $id, 'from_barangay' => null, 'name' => $name, 'head_name' => $head, 'member_count' => $members,
            'location' => 'inside_center', 'host_name' => null,
            'staying_in' => ['barangay' => ['id' => 1, 'name' => 'Tuburan'], 'center' => ['id' => 1, 'name' => 'Tuburan Barangay Hall'], 'private_house' => false, 'other_barangay' => false],
            'may_give' => true, 'priority_tier' => $tier, 'priority_reason' => null, 'priority_label' => $label,
            'given' => false, 'handout_id' => null, 'given_at' => null, 'given_by' => null, 'given_on_behalf' => false, 'voided_handouts' => [],
            'higher_priority_waiting' => $higher, 'next_in_line' => $next, 'can_give' => $received > 0, 'priority_order' => $order,
        ];

        return ['data' => [
            'round' => ['id' => 1, 'round_number' => 1, 'opened_at' => '2026-11-23T16:29:30+00:00'],
            'barangay' => ['id' => 1, 'name' => 'Tuburan'],
            'figures' => ['need' => 4, 'dispatched' => $received, 'in_transit' => 0, 'received' => $received, 'given' => 0, 'left_to_give' => $received, 'short' => 0],
            'next_family_id' => 2,
            'order' => 'priority',
            'staying_elsewhere' => [[
                'id' => 4, 'name' => 'Garcia', 'head_name' => 'Ana Garcia', 'member_count' => 1, 'purok' => ['id' => 1, 'name' => 'Purok 1'],
                'staying_in' => ['barangay' => ['id' => 2, 'name' => 'Bay'], 'center' => ['id' => 2, 'name' => 'Bay Elementary School'], 'private_house' => false, 'other_barangay' => true],
                'gives_box' => ['id' => 2, 'name' => 'Bay'], 'may_give' => false, 'given' => false, 'given_at' => null,
            ]],
            'puroks' => [
                ['purok' => ['id' => 1, 'name' => 'Purok 1'], 'given' => 0, 'families' => [
                    $fam(2, 'Dela Cruz', 'Maria Dela Cruz', 1, 3, 'PWD / senior', 1, 0, true),
                    $fam(3, 'Santos', 'Jose Santos', 1, null, null, 2, 1, false, ['location' => 'outside_center', 'host_name' => 'Juan Reyes',
                        'staying_in' => ['barangay' => ['id' => 1, 'name' => 'Tuburan'], 'center' => null, 'private_house' => true, 'other_barangay' => false]]),
                    $fam(5, 'Reyes', 'Lito Reyes', 3, null, null, 3, 1, false, ['from_barangay' => ['id' => 3, 'name' => 'Bay']]),
                ]],
            ],
        ]];
    }

    private function serve(int $received = 3): void
    {
        Http::fake([
            '*/box-relief/barangay*' => Http::response(['data' => ['need_basis' => 'registered', 'rounds' => [['id' => 1, 'round_number' => 1]]]]),
            '*/box-relief/rounds/1/families*' => Http::response($this->families($received)),
        ]);
        app(BoxRelief::class)->refresh(LocalAuth::current(), $this->event);
    }

    public function test_the_list_shows_priority_next_in_line_and_families_staying_elsewhere(): void
    {
        $this->serve();

        $this->get(route('relief.index'))->assertOk()
            ->assertSee('Round 1')->assertSee('Based on the registered count, not yet validated')
            ->assertSeeInOrder(['Maria Dela Cruz', 'Next in line', 'Priority: PWD / senior', 'Jose Santos', 'Private house', 'Juan Reyes'])
            ->assertSee('From Bay')
            ->assertSee('data-staying-elsewhere', false)->assertSee('Ana Garcia')->assertSee('Bay gives the box')
            // The out-of-priority question only on families with higher-priority families waiting.
            ->assertSee('data-confirm="1 higher-priority family has not received a box yet. Continue?"', false);

        // No Give button for a family staying elsewhere.
        $this->post(route('relief.give'), ['round_id' => 1, 'family_id' => 4])
            ->assertSessionHasErrors(['family_id' => 'Ana Garcia is staying in Bay, which gives the box.']);
    }

    public function test_a_box_given_offline_shows_at_once_and_is_sent_once_with_its_client_ref(): void
    {
        $this->serve();
        $online = false;
        Http::fake(function ($request) use (&$online) {
            if (! $online) {
                throw new ConnectionException('down');
            }

            return Http::response(['success' => true, 'message' => 'Box given.', 'data' => ['id' => 9, 'family_id' => 2, 'higher_priority_waiting' => 0]], 201,
                ['Idempotent-Replayed' => 'true']);
        });

        $this->post(route('relief.give'), ['round_id' => 1, 'family_id' => 2])->assertSessionHas('status', 'Box given to Maria Dela Cruz. It is sent with the next sync.');
        $handout = PendingHandout::sole();
        $this->assertSame(1, app(LocalData::class)->pendingCount());

        $view = app(BoxRelief::class)->forEvent($this->event);
        $this->assertSame(2, $view['figures']['left_to_give']);
        $this->assertSame(3, $view['next_family_id'], 'Next in line moves on.');
        $this->get(route('relief.index'))->assertSee('Given, waiting to sync');
        // Twice is refused on this computer.
        $this->post(route('relief.give'), ['round_id' => 1, 'family_id' => 2])->assertSessionHasErrors(['family_id' => 'This family already got its box for Round 1.']);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'stopped', 'stop' => 'offline']);
        $online = true;
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/box-relief/rounds/1/handouts') && $r['family_id'] === 2 && $r['client_ref'] === $handout->client_ref);
        $this->assertSame(9, (int) $handout->fresh()->handout_remote_id);
    }

    public function test_out_of_priority_gives_are_recorded_and_the_order_is_worked_out_again(): void
    {
        $this->serve();
        Http::fake(fn () => throw new ConnectionException('down'));

        // Santos before the PWD / senior family: allowed, after the question.
        $this->post(route('relief.give'), ['round_id' => 1, 'family_id' => 3])->assertSessionHasNoErrors();
        $this->assertSame(1, PendingHandout::sole()->higher_priority_waiting);

        $view = app(BoxRelief::class)->forEvent($this->event);
        $reyes = $view['puroks'][0]['families']->firstWhere('id', 5);
        $this->assertSame(1, $reyes['higher_priority_waiting']);
        $this->assertSame(2, $view['next_family_id']);
    }

    public function test_no_box_is_given_beyond_what_was_received(): void
    {
        $this->serve(1);
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->post(route('relief.give'), ['round_id' => 1, 'family_id' => 2])->assertSessionHasNoErrors();
        $this->post(route('relief.give'), ['round_id' => 1, 'family_id' => 3])->assertSessionHasErrors(['family_id' => 'All 1 boxes received for Round 1 have been given.']);
        $this->get(route('relief.index'))->assertSee('No box left')->assertDontSee('data-give-box', false);
    }

    public function test_handing_out_waits_while_the_event_is_monitored(): void
    {
        $this->serve();
        $this->event->update(['status' => 'monitoring']);

        $this->get(route('relief.index'))->assertSee('data-lock-notice="monitoring"', false)->assertDontSee('data-give-box', false);
        $this->post(route('relief.give'), ['round_id' => 1, 'family_id' => 2])
            ->assertSessionHasErrors(['evacuation_event_id' => '"Typhoon Ofel" is being monitored. Handing out relief boxes opens when CSWD starts the evacuation.']);
        $this->assertSame(0, PendingHandout::count());
    }

    public function test_the_question_reads_like_the_web(): void
    {
        $this->assertSame('1 higher-priority family has not received a box yet. Continue?', BoxRelief::outOfPriorityQuestion(1));
        $this->assertSame('3 higher-priority families have not received a box yet. Continue?', BoxRelief::outOfPriorityQuestion(3));
    }
}
