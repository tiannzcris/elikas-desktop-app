<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\DownloadLog;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\FileSaver;
use App\Services\LocalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The official EC Information Board from the server (11-24 doc 3) and every saved file reported (4). */
class ServerBoardAndDownloadReportsTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationCenter $center;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'Official One', 'email' => 'brgy.tuburan', 'role' => 'barangay_official',
            'barangay_id' => 1, 'barangay_name' => 'Tuburan', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 1, 'name' => 'Tuburan']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon Ofel', 'event_type' => 'typhoon', 'status' => 'active', 'is_demo' => true]);
        $this->center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Tuburan Barangay Hall', 'status' => 'active']);
    }

    private function fakeServer(): void
    {
        Http::fake([
            '*/reports/ec-information-board' => Http::response(['success' => true, 'message' => 'Report generated successfully.', 'data' => [
                'id' => 7, 'evacuation_event' => ['id' => 1, 'name' => 'Typhoon Ofel'], 'evacuation_center' => ['id' => 1, 'name' => 'Tuburan Barangay Hall'],
                'report_type' => 'ec_information_board', 'file_format' => 'xlsx', 'generated_by' => 'Official One',
                'download_url' => 'https://e-likasligao.online/api/v1/reports/7/download']], 201),
            '*/reports/7/download' => Http::response('PK-the-workbook', 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="DEMO-not-for-submission_EC-Information-Board_Tuburan-Barangay-Hall.xlsx"']),
            '*/file-downloads' => Http::response(['success' => true, 'message' => 'File recorded in the activity log.', 'data' => ['id' => 3]], 201),
        ]);
    }

    public function test_the_official_board_is_made_by_the_server_and_keeps_its_file_name(): void
    {
        $this->fakeServer();
        $this->get(route('downloads.index'))->assertSee('Official EC Information Board (from the server)');

        $response = $this->post(route('downloads.official'), ['center' => $this->center->id])->assertOk();
        $this->assertSame('PK-the-workbook', $response->getContent());
        $this->assertStringContainsString('filename="DEMO-not-for-submission_EC-Information-Board_Tuburan-Barangay-Hall.xlsx"', $response->headers->get('Content-Disposition'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/reports/ec-information-board') && $r['evacuation_event_id'] === 1 && $r['evacuation_center_id'] === 1);

        $log = DownloadLog::sole();
        $this->assertSame(['ec_information_board', 'xlsx', 7, false], [$log->file_type, $log->format, (int) $log->report_remote_id, $log->generated_offline]);
    }

    public function test_in_the_desktop_app_the_official_board_is_saved_where_the_user_chooses(): void
    {
        $this->fakeServer();
        $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'elikas-official-'.uniqid().'.xlsx';
        $this->app->instance(FileSaver::class, new class($target) extends FileSaver {
            public function __construct(private string $target) {}
            public function native(): bool { return true; }
            public function askPath(string $title, string $fileName, string $format): ?string { return $fileName === 'DEMO-not-for-submission_EC-Information-Board_Tuburan-Barangay-Hall.xlsx' ? $this->target : null; }
        });

        $this->post(route('downloads.official'), ['center' => $this->center->id])->assertSessionHas('status', "Saved to {$target}. This file contains personal information. Keep it secure.");
        $this->assertSame('PK-the-workbook', file_get_contents($target));
        @unlink($target);
    }

    public function test_offline_or_monitored_the_official_board_says_why(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->post(route('downloads.official'), ['center' => $this->center->id])->assertSessionHasErrors('download');
        $this->assertSame(0, DownloadLog::count());

        EvacuationEvent::first()->update(['status' => 'monitoring']);
        $this->post(route('downloads.official'), ['center' => $this->center->id])
            ->assertSessionHasErrors(['download' => '"Typhoon Ofel" is being monitored. EC Information Boards opens when CSWD starts the evacuation.']);
    }

    public function test_every_saved_file_is_reported_once_through_the_queue(): void
    {
        // Saved offline: a file made on this computer.
        $this->post(route('downloads.save'), ['document' => 'families-to-evacuate', 'format' => 'xlsx'])->assertOk();
        $log = DownloadLog::sole();
        $this->assertSame(1, app(LocalData::class)->pendingCount(), 'A report waiting counts as a waiting record.');
        $this->get(route('downloads.index'))->assertSee('Waiting to sync');

        $this->fakeServer();
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/file-downloads')
            && $r['file_type'] === 'families_to_evacuate' && $r['file_format'] === 'xlsx' && $r['action'] === 'saved'
            && $r['evacuation_event_id'] === 1 && $r['client_ref'] === $log->client_ref
            && $r['saved_at'] === $log->created_at->copy()->timezone('Asia/Manila')->toIso8601String()
            && ! isset($r['barangay_id']));
        $this->assertNotNull($log->fresh()->reported_at);
        $this->assertSame(0, app(LocalData::class)->pendingCount());
        $this->get(route('downloads.index'))->assertSee('Reported');
    }

    public function test_a_page_printed_from_the_browser_is_reported_as_printed(): void
    {
        $this->post(route('downloads.save'), ['document' => 'families-to-evacuate', 'format' => 'pdf'])->assertOk();
        $this->assertSame('printed', DownloadLog::sole()->toSyncPayload()['action']);
    }
}
