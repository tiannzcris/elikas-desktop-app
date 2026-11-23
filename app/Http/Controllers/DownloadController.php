<?php

namespace App\Http\Controllers;

use App\Models\DownloadLog;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Exceptions\CentralApiException;
use App\Services\CentralApiService;
use App\Services\Documents;
use App\Support\EventLock;
use App\Services\FileSaver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Print and download, for barangay officials only: files made on this
 * computer from the data it holds. Each one is confirmed first ("This file
 * contains personal information. Keep it secure."), saved where the user
 * chooses, and recorded (download_logs). The record stays on this computer:
 * the copied API notes have no endpoint to report downloads to yet.
 */
class DownloadController extends Controller
{
    public function index(Request $request)
    {
        $auth = LocalAuth::current();

        return view('downloads.index', [
            'currentUser' => $auth,
            'event' => EvacuationEvent::working(),
            'documents' => Documents::DOCUMENTS,
            'centers' => $this->centers($auth),
            'selectedCenter' => $request->integer('center') ?: null,
            'recent' => DownloadLog::latest()->limit(15)->get(),
        ]);
    }

    public function save(Request $request, Documents $documents, FileSaver $saver)
    {
        $auth = LocalAuth::current();
        $validated = $request->validate([
            'document' => ['required', Rule::in(array_keys(Documents::DOCUMENTS))],
            'format' => ['required', Rule::in(['pdf', 'xlsx'])],
            'center' => ['nullable', 'integer'],
        ]);
        $document = $validated['document'];
        $format = $validated['format'];
        abort_unless(in_array($format, Documents::DOCUMENTS[$document]['formats'], true), 422);

        $event = EvacuationEvent::working();
        if (! $event) {
            return back()->withErrors(['download' => EvacuationEvent::NO_OPEN_EVENT]);
        }
        $center = null;
        if (Documents::DOCUMENTS[$document]['per_center']) {
            $center = $this->centers($auth)->firstWhere('id', (int) ($validated['center'] ?? 0));
            if (! $center) {
                return back()->withErrors(['download' => 'Choose one of your barangay\'s centers.']);
            }
        }

        $data = $documents->data($document, $auth, $event, $center);
        $fileName = Documents::fileName($document, $format, $event, $center);

        if ($format === 'pdf') {
            $bytes = $documents->pdf($document, $data);
            if ($bytes === null) {
                // No PDF engine outside the desktop app: the page to print.
                $this->record($auth, $document, 'pdf', $fileName, $event, $center, 'Printed from the browser', 'printed');

                return response($documents->html($document, $data + ['printBar' => true]));
            }
        } else {
            $bytes = $documents->xlsx($document, $data);
        }

        if (! $saver->native()) {
            $this->record($auth, $document, $format, $fileName, $event, $center, null);

            return response($bytes, 200, [
                'Content-Type' => Documents::MIME[$format],
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            ]);
        }

        $path = $saver->askPath('Save '.Documents::DOCUMENTS[$document]['label'], $fileName, $format);
        if ($path === null) {
            return back()->with('status', 'Not saved.');
        }
        if (! $saver->write($path, $bytes)) {
            return back()->withErrors(['download' => "Could not save to {$path}. Choose another folder."]);
        }
        $this->record($auth, $document, $format, basename($path), $event, $center, $path);

        return back()->with('status', "Saved to {$path}. This file contains personal information. Keep it secure.");
    }

    /**
     * The official EC Information Board, made by the server for one center
     * (POST /reports/ec-information-board, then GET /reports/{id}/download).
     * Online only; the file keeps the server's name.
     */
    public function official(Request $request, CentralApiService $api, FileSaver $saver)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();
        if ($lock = EventLock::reason($event, EventLock::EC_BOARDS)) {
            // A closed event's board can still be made (the server allows it).
            if (! $event?->isClosed()) {
                return back()->withErrors(['download' => $lock]);
            }
        }
        $center = $this->centers($auth)->firstWhere('id', $request->integer('center'));
        if (! $center) {
            return back()->withErrors(['download' => 'Choose one of your barangay\'s centers.']);
        }

        try {
            $report = $api->send('post', 'reports/ec-information-board', $auth->api_token, [
                'evacuation_event_id' => $event->remote_id, 'evacuation_center_id' => $center->remote_id,
            ], 60)['data'] ?? [];
            $file = $api->download("reports/{$report['id']}/download", $auth->api_token);
        } catch (CentralApiException $e) {
            return back()->withErrors(['download' => $e->isOffline() ? 'Needs a connection: the official board is made by the server. The board made on this computer works offline.' : $e->getMessage()]);
        }

        $format = $report['file_format'] ?? 'xlsx';
        $fileName = $file['filename'] ?: Documents::fileName('ec-board', $format, $event, $center);

        if (! $saver->native()) {
            $this->record($auth, 'ec-board-official', $format, $fileName, $event, $center, null, 'saved', (int) $report['id'], false);

            return response($file['bytes'], 200, [
                'Content-Type' => $file['content_type'] ?: (Documents::MIME[$format] ?? 'application/octet-stream'),
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            ]);
        }

        $path = $saver->askPath('Save the official EC Information Board', $fileName, $format);
        if ($path === null) {
            return back()->with('status', 'Not saved.');
        }
        if (! $saver->write($path, $file['bytes'])) {
            return back()->withErrors(['download' => "Could not save to {$path}. Choose another folder."]);
        }
        $this->record($auth, 'ec-board-official', $format, basename($path), $event, $center, $path, 'saved', (int) $report['id'], false);

        return back()->with('status', "Saved to {$path}. This file contains personal information. Keep it secure.");
    }

    private function centers(LocalAuth $auth)
    {
        return EvacuationCenter::where('barangay_remote_id', $auth->barangay_id)->where('status', '!=', 'closed')
            ->where('approval_status', '!=', 'rejected')->orderBy('name')->get();
    }

    private function record(LocalAuth $auth, string $document, string $format, string $fileName, EvacuationEvent $event, ?EvacuationCenter $center, ?string $savedTo,
        string $action = 'saved', ?int $reportId = null, bool $offline = true): void
    {
        DownloadLog::create([
            'action' => $action,
            'report_remote_id' => $reportId,
            'document' => $document,
            'format' => $format,
            'file_name' => $fileName,
            'evacuation_event_remote_id' => $event->remote_id,
            'evacuation_center_remote_id' => $center?->remote_id,
            'user_remote_id' => (int) $auth->remote_user_id,
            'user_name' => $auth->name,
            'saved_to' => $savedTo,
            'generated_offline' => $offline,
        ]);
    }
}
