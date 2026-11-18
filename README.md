# E-LIKAS Offline Companion (Windows)

Desktop companion to the E-LIKAS web system for barangay halls and the CSWDO
office where the internet is weak. Laravel 12 inside NativePHP/Electron, local
SQLite, talking to the central API (`/api/v1`). Version 2.0.2, aligned with
the web backend at df031fe (API notes copied in `docs/backend/`) and its design
system. Screenshots at 1366x768: `docs/screenshots/2.0.2/`.

## Who uses it

| Account | On the desktop |
| --- | --- |
| Barangay official | EC Board for the own barangay's centers: Add evacuee (new or existing family, purok), Private houses (families with relatives or friends), Returning families, whole-family check-out. Works offline; Sync sends everything when the server answers. Transfers (online): move ticked families to another own center, or ask another barangay's center; accept or decline requests to the barangay. |
| CSWD personnel | Read-only Monitoring (with evacuation orders) and Final counts (with late entries), kept for offline reading, marked "As of ...". Online: Submit for a barangay with no official account, the Transfers screen, and Move a family with a reason. |
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
  "No open event" and "event closed" answers wait there too and are never
  resent by themselves; a closed event is read-only on the board.
- The top bar holds the event switcher (the server remembers the selected event
  per user; a choice made offline is sent later), the bell (transfer
  notifications open the Transfers screen from their href) and the chip.
- The top-bar chip says how it stands: "Offline · 3 records waiting",
  "Sending…", "All sent", "Needs attention".
- Logout revokes the token, keeps unsent records for the same account's next
  login, and removes copies of server data.

## Develop and test

    composer install && npm install
    php artisan test            # PHP 8.3+ (e.g. Herd's php84)
    php artisan elikas:api-target http://127.0.0.1:8000/api/v1   # point at a local backend
    npm run check:layout -- <out-dir> 1366x768 dash=http://127.0.0.1:8000/dashboard   # layout check + screenshot

## Build the Windows installer

    npm run build
    php artisan native:build win x64

The installer lands in `dist/` (`E-LIKAS-2.0.2-setup.exe`). `CENTRAL_API_URL`
in `.env` must be the production URL when building.
