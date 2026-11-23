# E-LIKAS Offline Companion (Windows)

Desktop companion to the E-LIKAS web system for barangay halls and the CSWDO
office where the internet is weak. Laravel 12 inside NativePHP/Electron, local
SQLite, talking to the central API (`/api/v1`). Version 2.1.0, aligned with the
backend's API notes of November 22-23, 2026 (`docs/backend/`) and its design
system. Screenshots at 1366x768: `docs/screenshots/2.1.0/`. Before a release,
test with `docs/windows-test-checklist-2.1.0.md`.

The desktop app is the barangay's offline encoding station; CSWD reads
(Monitoring, Final counts) and acts on the web.

## Who uses it

| Account | On the desktop |
| --- | --- |
| Barangay official | EC Board for the own barangay's centers: Add evacuee (new or existing family, purok), Private houses (families with relatives or friends), Returning families, Check out and Transfer per family, "Ask another center for space" at 90%. Families to evacuate per purok (fast keyboard entry, high-risk puroks, Check in offline). Final count (submit online, late entries). Print and download (EC Information Board, family list, relief distribution sheet, families to evacuate; made offline). Works offline; Sync sends everything when the server answers. Transfers (online): move families to another own center, or ask another barangay's center; accept or decline requests to the barangay. |
| CSWD personnel | Read-only Monitoring (with evacuation orders) and Final counts (with late entries), kept for offline reading, marked "As of ...". Online: Submit for a barangay with no official account, the Transfers screen, and Move a family with a reason. |
| Administrator | "Use the web" screen. Nothing is kept. |

Screens follow the permissions from `/auth/login` and `/auth/me`, never the role name.

## Offline and sync

- Copies kept on the device (refreshed at login, with "Refresh reference data",
  and on reconnect): barangays, events, own centers (with approval
  state), puroks, families checked in per center and event, returning families
  per open event, and for CSWD the Monitoring and Final counts pages.
- Work queued on the device: EC Board entries, private houses, returning
  families, check-outs, check-ins of listed families.
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

## Events: monitored, then active

While CSWD monitors an event (a threat is announced), registering, transfers,
final counts and check-ins of listed families wait for CSWD's "Start
evacuation": every screen shows the same lock, in the server's words, and an
official can tell CSWD "Families are arriving". Listing families to evacuate
is open. Once active, the screens say "Registration is open". A closed event
is read-only. The default event at sign-in is an active one, else a monitored
one, the newest first; practice events ("Typhoon Drill", "Incoming Typhoon")
look like any other (no DEMO badge; `is_demo` is internal only).

## Personal data on the computer

See `docs/local-data-protection.md`. In short: personal data is encrypted at
rest with a key made for this computer and protected with its Windows account;
the screen signs out after 15 minutes without activity (set on Data and
security; signing in again works offline); a closed event's data leaves the
computer once everything is sent; waiting records can be exported to an
encrypted file (USB drive) and imported on another computer under the same
account. Every file with personal information saved from the app is recorded
on the computer. `php artisan elikas:local-data status|encrypt|restore`.

## Accounts and the server

Sign in with email or username. A temporary password must be changed first; a
locked account and maintenance show the server's message. Every request sends
`X-Device-Id`, `X-App-Version`, `X-App-Platform`, `X-Sync-Pending`,
`X-Sync-Failed` and `X-Last-Sync`. System notices show as a banner; Report a
problem (with an optional screenshot) is in the account menu.

## 2.1.0: waiting for the server

- Relief boxes per family (Priority, Next in line, handouts queued with
  `client_ref`) are not in 2.1.0: the API notes in `docs/backend/` do not
  describe the handout endpoints yet.
- The official EC Information Board from the server needs its endpoint.
- Downloads are recorded on the computer only; reporting them to the server
  needs an endpoint.

- Adding families to evacuate is online only: `POST /families-to-evacuate`
  takes no `client_ref`, so it cannot be queued safely (check-ins can).

## Develop and test

    composer install && npm install
    php artisan test            # PHP 8.3+ (e.g. Herd's php84)
    php artisan elikas:api-target http://127.0.0.1:8000/api/v1   # point at a local backend
    npm run check:layout -- <out-dir> 1366x768 dash=http://127.0.0.1:8000/dashboard   # layout check + screenshot

For the layout check and screenshots, use a separate database with fictional
data and an unreachable server, never a device's own database:

    touch /tmp/layout.sqlite
    DB_DATABASE=/tmp/layout.sqlite php artisan migrate --force
    DB_DATABASE=/tmp/layout.sqlite php artisan db:seed --class=LayoutCheckSeeder --force   # LAYOUT_ROLE=cswd for CSWD
    DB_DATABASE=/tmp/layout.sqlite CENTRAL_API_URL=http://127.0.0.1:9/api/v1 SESSION_DRIVER=file \
      ELIKAS_LOCAL_DATA_KEY_PATH=/tmp/layout.key php artisan serve --port=8123

## Build the Windows installer

    npm run build
    php artisan native:build win x64

The installer lands in `dist/` (`E-LIKAS-2.1.0-setup.exe`). Upload it to the
hosting as `downloads/E-LIKAS-Setup.exe` (the website's download link), and
upload `docs/hosting/desktop-version.json` (with the new version in `latest`)
to `downloads/desktop-version.json`: installed apps check it and show "A new
version of E-LIKAS is available" with a Download button. `CENTRAL_API_URL`
in `.env` must be the production URL when building.
