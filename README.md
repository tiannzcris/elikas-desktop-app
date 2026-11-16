# E-LIKAS Offline Companion (Windows)

Desktop companion to the E-LIKAS web system for barangay halls and the CSWDO
office where the internet is weak. Laravel 12 inside NativePHP/Electron, local
SQLite, talking to the central API (`/api/v1`). Version 2.0.0.

## Who uses it

| Account | On the desktop |
| --- | --- |
| Barangay official | EC Board for the own barangay's centers: Add evacuee (new or existing family, purok), Returning families, whole-family check-out. Works offline; Sync sends everything when the server answers. |
| CSWD personnel | Read-only Monitoring and Final counts, kept for offline reading, marked "As of ..." with "Validate on the web". |
| Administrator | "Use the web" screen. Nothing is kept. |

Screens follow the permissions from `/auth/login` and `/auth/me`, never the role name.

## Offline and sync

- Copies kept on the device (refreshed at login, with "Refresh reference data",
  and on reconnect): barangays, events (with DEMO), own centers (with approval
  state), puroks, families checked in per center and event, returning families
  per open event, and for CSWD the Monitoring and Final counts pages.
- Work queued on the device: EC Board entries, returning families, check-outs.
  Each record has its own `client_ref` (UUID); the server returns the first
  answer for a repeat, so a retry never makes a duplicate
  (elikas-backend `docs/client-ref-api.md`).
- Sync sends one record per request, oldest first; a record waits for the
  family it belongs to. It runs by itself whenever the server answers, and with
  Sync now. Refused records and "same family?" questions wait on the Sync page.
- Logout revokes the token, keeps unsent records for the same account's next
  login, and removes copies of server data.

## Develop and test

    composer install && npm install
    php artisan test            # PHP 8.3+ (e.g. Herd's php84)
    php artisan elikas:api-target http://127.0.0.1:8000/api/v1   # point at a local backend

## Build the Windows installer

    npm run build
    php artisan native:build win x64

The installer lands in `dist/` (`E-LIKAS-2.0.0-setup.exe`). `CENTRAL_API_URL`
in `.env` must be the production URL when building.
