<?php

namespace App\Http\Controllers;

use App\Models\DownloadLog;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\Documents;
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
                $this->record($auth, $document, 'pdf', $fileName, $event, $center, 'Printed from the browser');

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

    private function centers(LocalAuth $auth)
    {
        return EvacuationCenter::where('barangay_remote_id', $auth->barangay_id)->where('status', '!=', 'closed')
            ->where('approval_status', '!=', 'rejected')->orderBy('name')->get();
    }

    private function record(LocalAuth $auth, string $document, string $format, string $fileName, EvacuationEvent $event, ?EvacuationCenter $center, ?string $savedTo): void
    {
        DownloadLog::create([
            'document' => $document,
            'format' => $format,
            'file_name' => $fileName,
            'evacuation_event_remote_id' => $event->remote_id,
            'evacuation_center_remote_id' => $center?->remote_id,
            'user_remote_id' => (int) $auth->remote_user_id,
            'user_name' => $auth->name,
            'saved_to' => $savedTo,
            'generated_offline' => true,
        ]);
    }
}
