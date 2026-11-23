<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\DownloadLog;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\Purok;
use App\Models\ServerSnapshot;
use App\Services\FileSaver;
use App\Services\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 2.1.0 part 7: print and download, generated offline, warned, saved where chosen, recorded. */
class DownloadsTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationCenter $center;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'Maria Santos', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon Rolly', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);
        CenterFamilySnapshot::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $event->id, 'fetched_at' => now(),
            'families' => [['id' => 501, 'name' => 'Jose Reyes', 'here_count' => 4, 'purok' => ['name' => 'Purok 1']]]]);
        Purok::create(['remote_id' => 13, 'barangay_remote_id' => 4, 'name' => 'Purok 3', 'high_risk_flood' => true]);
        ServerSnapshot::keep('families_to_evacuate.79', ['families' => [
            ['id' => 302, 'head' => 'Ana Cruz', 'members_count' => 5, 'purok' => ['id' => 13, 'name' => 'Purok 3'], 'status' => 'expected'],
        ]]);
    }

    private function save(array $fields)
    {
        return $this->post(route('downloads.save'), $fields);
    }

    public function test_the_page_warns_before_saving(): void
    {
        $this->get(route('downloads.index'))->assertOk()
            ->assertSee('data-confirm="This file contains personal information. Keep it secure.', false)
            ->assertSee('EC Information Board')->assertSee('Family list')->assertSee('Relief distribution sheet')->assertSee('Families to evacuate per purok');
    }

    public function test_an_excel_family_list_is_made_offline_and_recorded(): void
    {
        $response = $this->save(['document' => 'family-list', 'format' => 'xlsx'])->assertOk();
        $this->assertStringContainsString('attachment; filename="family-list_typhoon-rolly_', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $response->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('Jose Reyes', $sheet);
        $this->assertStringContainsString('Generated offline, as of', $sheet);
        $this->assertStringContainsString('May not include records not yet synced.', $sheet);
        $this->assertStringContainsString('This file contains personal information. Keep it secure.', $sheet);

        $log = DownloadLog::sole();
        $this->assertSame(['family-list', 'xlsx', 79, 1, 'Maria Santos'], [$log->document, $log->format, (int) $log->evacuation_event_remote_id, (int) $log->user_remote_id, $log->user_name]);
        $this->get(route('downloads.index'))->assertSee('data-download-log', false)->assertSee($log->file_name);
    }

    public function test_in_the_desktop_app_the_user_chooses_where_and_a_cancel_saves_nothing(): void
    {
        $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'elikas-test-'.uniqid().'.pdf';
        $this->app->instance(PdfRenderer::class, new class extends PdfRenderer {
            public function render(string $html): ?string { return "%PDF-1.4\n".$html; }
        });
        $saver = new class extends FileSaver {
            public ?string $answer = null;
            public function native(): bool { return true; }
            public function askPath(string $title, string $fileName, string $format): ?string { return $this->answer; }
        };
        $this->app->instance(FileSaver::class, $saver);

        // Cancelled: nothing written, nothing recorded.
        $this->save(['document' => 'relief-sheet', 'format' => 'pdf', 'center' => $this->center->id])->assertSessionHas('status', 'Not saved.');
        $this->assertSame(0, DownloadLog::count());

        $saver->answer = $target;
        $this->save(['document' => 'relief-sheet', 'format' => 'pdf', 'center' => $this->center->id])
            ->assertSessionHas('status', "Saved to {$target}. This file contains personal information. Keep it secure.");
        $pdf = file_get_contents($target);
        @unlink($target);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('Signature', $pdf);
        $this->assertStringContainsString('Jose Reyes', $pdf);
        $this->assertStringContainsString('Generated offline, as of', $pdf);
        $this->assertSame($target, DownloadLog::sole()->saved_to);
    }

    public function test_the_ec_board_and_the_families_to_evacuate_list_print(): void
    {
        $this->save(['document' => 'ec-board', 'format' => 'pdf', 'center' => $this->center->id])->assertOk()
            ->assertSee('EC Information Board')->assertSee('Sectoral groups')->assertSee('Print or save as PDF');
        $this->save(['document' => 'families-to-evacuate', 'format' => 'pdf'])->assertOk()
            ->assertSeeInOrder(['Purok 3', 'HIGH RISK: Flood', 'Ana Cruz']);
        $this->assertSame(2, DownloadLog::count());
    }

    public function test_a_practice_events_files_are_named_not_for_submission(): void
    {
        EvacuationEvent::first()->update(['is_demo' => true]);
        $response = $this->save(['document' => 'families-to-evacuate', 'format' => 'xlsx']);
        $this->assertStringContainsString('filename="DEMO-not-for-submission_families-to-evacuate-per-purok_', $response->headers->get('Content-Disposition'));
    }

    public function test_cswd_has_no_print_and_download(): void
    {
        LocalAuth::query()->update(['role' => 'cswd_personnel', 'barangay_id' => null]);
        $this->get(route('downloads.index'))->assertRedirect();
        $this->save(['document' => 'family-list', 'format' => 'xlsx'])->assertRedirect();
        $this->assertSame(0, DownloadLog::count());
    }
}
