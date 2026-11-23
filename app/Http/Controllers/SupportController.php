<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use App\Services\CentralApiService;
use App\Services\SystemNotices;
use Illuminate\Http\Request;

/**
 * Report a problem (POST /support-requests, with an optional screenshot)
 * and the account's own reports with the administrator's replies
 * (GET /support-requests/mine). Online only; the list is kept for offline.
 * Also the system notices banner's refresh.
 */
class SupportController extends Controller
{
    public function index(Request $request, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        abort_unless($auth->can('support.report'), 403);

        // The screen the report is about: where the user came from.
        $from = '/'.ltrim((string) parse_url((string) $request->query('from', url()->previous()), PHP_URL_PATH), '/');

        return view('support.index', [
            'currentUser' => $auth,
            'page' => $from === '/report-a-problem' ? '' : $from,
            'mine' => ServerSnapshot::named('support.mine'),
        ]);
    }

    public function refresh(CentralApiService $api)
    {
        try {
            $this->reloadMine($api);
        } catch (CentralApiException $e) {
            return back()->withErrors(['support' => $e->isOffline() ? 'Needs a connection. Showing your reports as of the last refresh.' : $e->getMessage()]);
        }

        return back()->with('status', 'Brought up to date.');
    }

    public function store(Request $request, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        abort_unless($auth->can('support.report'), 403);
        $validated = $request->validate([
            'what_happened' => ['required', 'string', 'min:10', 'max:2000'],
            'page' => ['nullable', 'string', 'max:255'],
            'screenshot' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ], [
            'what_happened.min' => 'Say what happened in at least 10 characters.',
            'screenshot.mimes' => 'The screenshot must be a PNG, JPG or WEBP picture.',
            'screenshot.max' => 'The screenshot can be at most 5 MB.',
        ]);

        $files = [];
        if ($request->hasFile('screenshot')) {
            $files['screenshot'] = ['contents' => (string) file_get_contents($request->file('screenshot')->getRealPath()),
                'filename' => $request->file('screenshot')->getClientOriginalName() ?: 'screenshot.png'];
        }

        try {
            $api->sendMultipart('support-requests', $auth->api_token, array_filter([
                'what_happened' => $validated['what_happened'],
                'page' => $validated['page'] ?? null,
                'source' => 'desktop',
            ]), $files);
        } catch (CentralApiException $e) {
            return back()->withInput()->withErrors(['support' => $e->isOffline()
                ? 'Needs a connection. Nothing was sent; your text is still here.'
                : $e->getMessage()]);
        }

        try {
            $this->reloadMine($api);
        } catch (CentralApiException $e) {
            // Sent; the list catches up at the next refresh.
        }

        return redirect()->route('support.index')->with('status', 'Thank you. Your report was sent to the administrator; the reply shows here and in the bell.');
    }

    /** The banner's own refresh (every few minutes while online): the notices showing now. */
    public function notices(SystemNotices $notices)
    {
        try {
            $notices->refresh(LocalAuth::current());
        } catch (CentralApiException $e) {
            // Offline: the last notices stay.
        }

        return response()->view('partials._system_notices', ['notices' => $notices->current()]);
    }

    private function reloadMine(CentralApiService $api): void
    {
        $rows = $api->send('get', 'support-requests/mine', LocalAuth::current()->api_token, [], 10)['data'] ?? [];
        ServerSnapshot::keep('support.mine', ['reports' => array_values($rows['data'] ?? $rows)]);
    }
}
