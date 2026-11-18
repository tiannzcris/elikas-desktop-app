<?php

namespace App\Http\Controllers;

use App\Models\AppState;
use Illuminate\Support\Facades\Http;
use Native\Laravel\Facades\Shell;

/**
 * "A new version is available": compares this app's version with the
 * latest one published next to the installer on the hosting
 * (config elikas.version_check_url, downloads/desktop-version.json).
 * Offline it answers from the last check. The download opens in the
 * computer's browser; the user installs it over this version (data kept).
 */
class AppVersionController extends Controller
{
    public function check()
    {
        $latest = AppState::get('latest_version');

        try {
            $response = Http::acceptJson()->timeout(5)->get(config('elikas.version_check_url'));
            if ($response->successful() && is_string($response->json('latest'))) {
                $latest = [
                    'latest' => $response->json('latest'),
                    'download_url' => $this->safeUrl($response->json('download_url')),
                    'checked_at' => now()->toIso8601String(),
                ];
                AppState::put('latest_version', $latest);
            }
        } catch (\Throwable $e) {
            // Offline, or no file yet: the last check stands.
        }

        $current = (string) config('nativephp.version');

        return response()->json([
            'current' => $current,
            'latest' => $latest['latest'] ?? null,
            'update' => isset($latest['latest']) && version_compare($latest['latest'], $current, '>'),
        ]);
    }

    /** Opens the installer's download in the computer's browser. */
    public function download()
    {
        $url = AppState::get('latest_version')['download_url'] ?? null ?: config('elikas.download_page_url');

        if (config('nativephp-internal.running')) {
            Shell::openExternal($url);

            return back()->with('status', 'The download opened in your browser. Run the installer when it finishes; your data on this computer is kept.');
        }

        return redirect()->away($url);
    }

    /** Only an https link is ever opened. */
    private function safeUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
