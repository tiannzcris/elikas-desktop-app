<?php

// Central server connection settings.
//
// CENTRAL_API_URL in .env is the single source of truth for what ships --
// it must always be the real production URL, since native:build bakes
// whatever .env says at build time straight into the packaged app (see
// the "Cleaning .env file..." build step, which only strips secret-shaped
// keys like AWS_*/GITHUB_*/*_SECRET, never this one). Never edit
// CENTRAL_API_URL in .env for local testing -- that risks a build shipping
// with the wrong target if the edit is ever left in place.
//
// For local/staging testing instead, use `php artisan elikas:api-target`
// (see app/Console/Commands/ApiTargetCommand.php), which writes a target
// URL to storage/app/dev-api-target.txt rather than touching .env at all.
// That file:
//   - is gitignored (storage/app/.gitignore excludes everything under it)
//   - is explicitly stripped from every packaged build (see
//     cleanup_exclude_files in config/nativephp.php) -- so even a
//     forgotten override can never ship, regardless of which machine or
//     .env state a build runs from.
// When present, it overrides CENTRAL_API_URL for THIS device's local
// SQLite-backed dev/test session only. layouts/app.blade.php shows a
// highly visible banner on every page whenever the resolved URL isn't
// the real production one, so a session pointed anywhere else is never
// silently mistaken for hitting the real CSWDO server (see
// PRODUCTION_API_URL below, and the exact incident this exists to
// prevent: a locally-run test evacuee entry synced straight into the
// live production database because there was previously no way to tell,
// from inside the app, which server a session was actually talking to).
return [
    'production_api_url' => 'https://e-likasligao.online/api/v1',

    // The latest desktop version, a small file next to the installer on the
    // hosting (downloads/desktop-version.json):
    // { "latest": "2.0.2", "download_url": "https://e-likasligao.online/downloads/E-LIKAS-Setup.exe" }
    // The app compares it with its own version and offers the download.
    'version_check_url' => env('ELIKAS_VERSION_CHECK_URL', 'https://e-likasligao.online/downloads/desktop-version.json'),
    'download_page_url' => 'https://e-likasligao.online/downloads/E-LIKAS-Setup.exe',

    // This computer's data key and backups (docs/local-data-protection.md).
    // Empty: storage/app/local-data.key and storage/app/backups.
    'local_data_key_path' => env('ELIKAS_LOCAL_DATA_KEY_PATH'),
    'local_backups_path' => env('ELIKAS_LOCAL_BACKUPS_PATH'),

    'central_api_url' => (function () {
        $overrideFile = storage_path('app/dev-api-target.txt');

        if (is_file($overrideFile)) {
            $override = trim(file_get_contents($overrideFile));
            if ($override !== '') {
                return $override;
            }
        }

        return env('CENTRAL_API_URL', 'http://127.0.0.1:8000/api/v1');
    })(),
];
