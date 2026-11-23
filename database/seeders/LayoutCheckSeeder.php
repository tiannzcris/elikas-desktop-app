<?php

namespace Database\Seeders;

use App\Models\AppState;
use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\Purok;
use App\Models\ServerSnapshot;
use Illuminate\Database\Seeder;

/**
 * Fictional data for the layout check and the screenshots
 * (npm run check:layout). Never run it on a real device's database: it
 * signs in a made-up account with a made-up token.
 *
 *   php artisan db:seed --class=LayoutCheckSeeder            (barangay official)
 *   LAYOUT_ROLE=cswd php artisan db:seed --class=LayoutCheckSeeder
 *
 * Serve the app against that database with an unreachable server address,
 * so nothing can be sent anywhere:
 *   DB_DATABASE=<file> CENTRAL_API_URL=http://127.0.0.1:9/api/v1 php artisan serve --port=8123
 */
class LayoutCheckSeeder extends Seeder
{
    public function run(): void
    {
        $cswd = env('LAYOUT_ROLE') === 'cswd';

        LocalAuth::query()->delete();
        LocalAuth::create([
            'remote_user_id' => $cswd ? 2 : 1,
            'name' => $cswd ? 'Ana Cruz' : 'Maria Santos',
            'email' => $cswd ? 'ana.cruz@example.test' : 'brgy.bacong@example.test',
            'role' => $cswd ? 'cswd_personnel' : 'barangay_official',
            'role_display_name' => $cswd ? 'CSWD Personnel' : 'Barangay Official',
            'permissions' => $cswd
                ? ['operations.access', 'scope.all_barangays', 'page.monitoring', 'puroks.view', 'count_submissions.review',
                    'count_submissions.submit_on_behalf', 'evacuees.transfer', 'transfers.manage', 'page.dashboard', 'support.report']
                : ['operations.access', 'page.monitoring', 'puroks.view', 'families.re_enroll', 'count_submissions.submit',
                    'box_relief.distribute', 'centers.edit', 'evacuees.manage', 'evacuees.transfer', 'transfers.request',
                    'transfers.receive', 'families_to_evacuate.manage', 'support.report'],
            'barangay_id' => $cswd ? null : 4,
            'barangay_name' => $cswd ? null : 'Bacong',
            'api_token' => 'layout-check-not-a-real-token',
            'logged_in_at' => now(),
            'identity_checked_at' => now(),
        ]);
        AppState::put('device_id', 'layout-check-device');
        AppState::put('system_notices', [['id' => 1, 'title' => 'New version 2.1.0', 'message' => 'Download it from the E-LIKAS website; your data on this computer is kept.', 'ends_at' => now()->addDays(3)->toIso8601String()]]);

        $bacong = Barangay::updateOrCreate(['remote_id' => 4], ['name' => 'Bacong']);
        Barangay::updateOrCreate(['remote_id' => 5], ['name' => 'Bonga']);

        $drill = EvacuationEvent::updateOrCreate(['remote_id' => 79], ['name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active', 'is_demo' => true, 'start_date' => now()->subDays(3)]);
        EvacuationEvent::updateOrCreate(['remote_id' => 80], ['name' => 'Incoming Typhoon', 'event_type' => 'typhoon', 'status' => 'monitoring', 'is_demo' => true, 'start_date' => now()->subDay()]);
        EvacuationEvent::updateOrCreate(['remote_id' => 60], ['name' => 'Typhoon Opong', 'event_type' => 'typhoon', 'status' => 'closed', 'start_date' => now()->subMonths(2)]);

        $centers = [
            [101, 'Bacong Elementary School', 160, 52, 'active', 'official'],
            [102, 'Bacong Covered Court', 120, 99, 'nearly_full', 'estimated'],
            [103, 'Bacong Barangay Hall', 40, 38, 'full', 'official'],
        ];
        foreach ($centers as [$id, $name, $cap, $inside, $status, $source]) {
            EvacuationCenter::updateOrCreate(['remote_id' => $id], [
                'barangay_remote_id' => 4, 'name' => $name, 'status' => 'active', 'approval_status' => 'approved',
                'capacity_persons' => $cap, 'capacity_source' => $source, 'capacity_basis' => $source === 'estimated' ? 'peak_2025' : null,
                'current_occupancy' => $inside, 'effective_status' => $status,
            ]);
        }

        foreach ([[11, 'Purok 1'], [12, 'Purok 2'], [13, 'Purok 3 (Riverside)'], [14, 'Purok 4']] as [$id, $name]) {
            Purok::updateOrCreate(['remote_id' => $id], ['barangay_remote_id' => 4, 'name' => $name]);
        }

        $names = ['Maria Santos', 'Jose Reyes', 'Ana Dela Cruz', 'Pedro Bautista', 'Rosa Garcia', 'Luis Mendoza', 'Carmen Villanueva', 'Ramon Aquino'];
        $school = EvacuationCenter::firstWhere('remote_id', 101);
        CenterFamilySnapshot::updateOrCreate(['evacuation_center_id' => $school->id, 'evacuation_event_id' => $drill->id], [
            'families' => collect($names)->map(fn ($n, $i) => ['id' => 500 + $i, 'name' => $n, 'here_count' => 3 + $i % 4,
                'purok' => ['id' => 11 + $i % 4, 'name' => 'Purok '.(1 + $i % 4)]])->all(),
            'fetched_at' => now()->subMinutes(12),
        ]);

        Purok::where('remote_id', 13)->update(['high_risk_flood' => true]);
        $listed = [[301, 'Jose Reyes', 3, 11], [302, 'Maria Santos', 4, 13], [303, 'Ana Dela Cruz', 6, 13], [304, 'Pedro Bautista', 2, 12], [305, 'Rosa Garcia', 5, 13]];
        foreach ([79, 80] as $eventId) {
            ServerSnapshot::keep('families_to_evacuate.'.$eventId, ['families' => collect($listed)->map(fn ($r) => ['id' => $r[0], 'head' => $r[1], 'members_count' => $r[2],
                'purok' => ['id' => $r[3], 'name' => 'Purok '.($r[3] - 10)], 'status' => $r[0] === 304 && $eventId === 79 ? 'checked_in' : 'expected', 'from_past_event' => $r[0] === 303])->all()]);
        }

        ServerSnapshot::keep('final_count.79', ['computed' => ['families' => 43, 'persons' => 163, 'families_in_centers' => 40, 'persons_in_centers' => 152,
            'families_in_private_houses' => 3, 'persons_in_private_houses' => 11, 'late_entries' => ['since' => now()->subHours(5)->toIso8601String(), 'families' => 3, 'persons' => 11, 'total' => 3],
            'checks' => ['incomplete_families' => 2, 'possible_duplicates' => 0]],
            'submissions' => [['id' => 41, 'status' => 'validated', 'families' => 40, 'persons' => 152, 'submitted_at' => now()->subHours(5)->toIso8601String(), 'submitted_on_behalf' => true,
                'note' => 'Submitted by CSWD on behalf of Bacong. Reason: The official could not be reached during the night.']]]);

        if ($cswd) {
            ServerSnapshot::keep('monitoring.overview', ['evacuation_event' => ['id' => 79, 'name' => 'Typhoon Drill', 'status' => 'active', 'is_demo' => true],
                'generated_at' => now()->subMinutes(5)->toIso8601String(),
                'data' => ['figures' => ['persons' => 1234, 'families' => 345, 'inside' => 1100, 'arrived_last_hour' => 42, 'centers_in_use' => 12,
                    'private_houses' => ['persons' => 134, 'families' => 31]],
                    'attention' => [['level' => 'danger', 'text' => 'Bacong Barangay Hall is at 95% of capacity.']]]]);
            ServerSnapshot::keep('final_counts', ['evacuation_event' => ['id' => 79, 'name' => 'Typhoon Drill'], 'barangays' => [
                ['barangay' => ['id' => 4, 'name' => 'Bacong'], 'current_submission' => ['status' => 'pending', 'families' => 40, 'persons' => 152]],
                ['barangay' => ['id' => 5, 'name' => 'Bonga'], 'current_submission' => null],
            ], 'submissions' => []]);
        }
    }
}
