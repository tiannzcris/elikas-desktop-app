<?php

namespace App\Http\Controllers;

use App\Models\DownloadLog;
use App\Models\LocalAuth;
use App\Services\FileSaver;
use App\Services\WaitingRecordsException;
use App\Services\WaitingRecordsTransfer;
use Illuminate\Http\Request;

/** Export and import waiting records (Data and security; barangay officials). */
class WaitingRecordsController extends Controller
{
    public function export(Request $request, WaitingRecordsTransfer $transfer, FileSaver $saver)
    {
        $auth = LocalAuth::current();
        $request->validate([
            'passphrase' => ['required', 'string', 'min:'.WaitingRecordsTransfer::MIN_PASSPHRASE, 'confirmed'],
        ], [
            'passphrase.min' => 'Use a passphrase of at least '.WaitingRecordsTransfer::MIN_PASSPHRASE.' characters.',
            'passphrase.confirmed' => 'The two passphrases are not the same.',
        ]);

        $file = $transfer->export($auth, (string) $request->input('passphrase'));
        if ($file['count'] === 0) {
            return back()->withErrors(['export' => 'Nothing is waiting to be sent: there is nothing to export.']);
        }

        if ($saver->native()) {
            $path = $saver->askPath('Save waiting records', $file['file_name'], 'elikas');
            if ($path === null) {
                return back()->with('status', 'Not saved.');
            }
            if (! $saver->write($path, $file['bytes'])) {
                return back()->withErrors(['export' => "Could not save to {$path}. Choose another folder or drive."]);
            }
        }
        DownloadLog::create(['document' => 'waiting-records', 'format' => 'elikas', 'file_name' => isset($path) ? basename($path) : $file['file_name'],
            'user_remote_id' => (int) $auth->remote_user_id, 'user_name' => $auth->name, 'saved_to' => $path ?? null, 'generated_offline' => true]);

        if (! $saver->native()) {
            return response($file['bytes'], 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="'.$file['file_name'].'"']);
        }

        return back()->with('status', \App\Support\Plural::of($file['count'], 'waiting record', 'waiting records')." exported to {$path}. They also stay on this computer; whichever computer sends them first, the server keeps them once.");
    }

    public function import(Request $request, WaitingRecordsTransfer $transfer)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'passphrase' => ['required', 'string'],
        ], ['file.required' => 'Choose the file of waiting records (.elikas).']);

        try {
            $result = $transfer->import(LocalAuth::current(), (string) file_get_contents($request->file('file')->getRealPath()), (string) $request->input('passphrase'));
        } catch (WaitingRecordsException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return redirect()->route('sync.index')->with('status', \App\Support\Plural::of($result['added'], 'record', 'records').' added to this computer\'s queue'
            .($result['skipped'] ? ' ('.\App\Support\Plural::of($result['skipped'], 'was', 'were').' already here)' : '')
            .'. They go with the next sync.');
    }
}
