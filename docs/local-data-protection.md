# How the desktop app protects personal data (2.1.0)

For whoever installs, supports or audits E-LIKAS on a barangay computer.

The desktop app keeps personal data on the computer so the barangay can keep
working without the internet. From 2.1.0 that data is encrypted, the screen
signs out when nobody uses it, and a closed event's data leaves the computer
once everything has been sent.

## What is encrypted

Personal values in the app's database are stored encrypted (AES-256-GCM):

| Table | Columns |
| --- | --- |
| `local_auth` | the sign-in token, the password hash used to sign in again offline |
| `families`, `evacuees` | names, address, contact number, birth date |
| `ec_board_entries` | the new household's head name |
| `center_family_snapshots`, `returning_family_snapshots` | the family lists copied from the server |
| `pending_re_enrollments`, `pending_check_outs`, `pending_private_house_families`, `pending_list_check_ins` | names, members, hosts of records waiting to be sent |
| `server_snapshots` | every page copied from the server (Monitoring, final counts, transfers, families to evacuate) |

Not encrypted: ids, counts, dates of records, flags such as sex or age group,
and reference data (barangays, events, centers, puroks). The signed-in staff
member's name and email are not encrypted. The full list is
`App\Security\LocalDataEncryption::COLUMNS`.

## The key

- One random 256-bit key per installation, made the first time it is needed.
- Kept in its own file, never in the database:
  `%APPDATA%\E-LIKAS\storage\app\local-data.key` (the app's storage folder).
- In the desktop app the key is itself encrypted with the **Windows account**
  that runs the app (Electron safeStorage, which uses Windows DPAPI). The
  database and the key file copied to another computer, or opened by another
  Windows account, cannot be read.
- It is never the Laravel `APP_KEY`, which is the same in every installer.
- The build never includes `storage/app/local-data.key`, `storage/app/backups`
  or `storage/app/exports` (`cleanup_exclude_files` in `config/nativephp.php`).

`php artisan elikas:local-data status` shows where the key and the database
are, how the key is protected, and how many personal values are still in
plain text (it should be 0).

## Upgrading from 2.0.x

The first start of 2.1.0 shows "Update required" (as every schema change
does). Pressing Update runs the migrations; the last one,
`2026_11_23_000038_protect_local_data`:

1. writes a **backup of the whole database** first, to
   `storage\app\backups\before-2.1.0-encryption-<date>-<time>.sqlite.enc`. The
   backup is a consistent copy (SQLite `VACUUM INTO`, so the write-ahead log
   is included), encrypted with the same key, and it is read back before
   anything changes;
2. encrypts every personal value inside one transaction;
3. reads every value back, decrypts it and compares it with the original. If
   any value differs, the transaction is rolled back and nothing changes;
4. leaves alone a value that is already encrypted, so running it again
   changes nothing.

Tested in `tests/Feature/LocalDataEncryptionTest.php`: every value of a
2.0.x-shaped database reads back the same, no row is added or lost, nothing
readable is left, a second run changes nothing, and a backup restores the
whole database. Before release it was also run on a copy of a real 2.0.x
development database: every table kept its row count, no personal value was
left in plain text, and the restored backup matched the original value for
value.

## Signing out after inactivity

- Without a key, click, scroll or mouse movement for **15 minutes** (5, 10,
  15, 30 or 60, on Data and security), the app signs the person out of the
  screen. The server-side check also catches a computer that was asleep or a
  window that was closed.
- Records waiting to be sent stay, and so does the account, so they can be
  sent after signing in again.
- At Log out, records already sent leave the computer, except those sent in
  the last 14 days: Sync > Sent lists them (what, where, when, by whom) and
  a sent registration can be corrected from there while online.
- Signing in again works **offline**: the password is checked against a
  bcrypt hash of the password last used to sign in online (stored encrypted).
  After 5 wrong passwords in a row the offline check stops for 15 minutes.
- An account signed in before 2.1.0 has no hash yet: the first time, the app
  needs the internet to check the password with the server.
- "Sign in as someone else" signs out fully (as Log out).

## Closed events

When an event is closed and every record for it has been sent, its personal
data leaves the computer: families, entries, the copied lists and boards. The
event itself stays so it can still be chosen to look back at, and the server
keeps everything. This happens after each refresh from the server and each
time the queue empties, and with "Remove now" on Data and security. An event
with records still waiting (or refused and not yet removed) is kept.

## Recovery

| Situation | What to do |
| --- | --- |
| The upgrade stopped with an error | Nothing was changed (the transaction rolled back). Note the message, keep the app closed and send the log (`storage\logs\laravel.log`) to support. The backup is in `storage\app\backups`. |
| The data must go back to how it was before the upgrade | Close the app. Run `php artisan elikas:local-data restore <backup.sqlite.enc> --to=<file.sqlite>` from the app's folder (support does this), keep a copy of the current database (`database\nativephp.sqlite` in the app's storage), then put the restored file in its place with the same name. The backup opens only with the same key file on the same Windows account. |
| "The key of this computer's data could not be opened with this Windows account" | The app is running under another Windows account, or Windows' own protection data was reset (for example, an administrator reset the account's password). Sign in to Windows as the account that installed and used E-LIKAS. If that is not possible, the encrypted data cannot be read by anyone. |
| The key file is lost or damaged | The encrypted data and the backups cannot be read. Copies of server data come back at the next sign-in. Records that were waiting to be sent are lost unless they were exported (Data and security, "Move waiting records to another computer") or sent first. |
| A computer is lost or stolen | The data cannot be read without the Windows account. Ask the administrator to sign the device out (Devices and sync), which stops its token. |

Good practice for barangay computers: one Windows account per computer with a
password, BitLocker on the drive where possible, sync before closing the app,
and export waiting records to a USB drive when the internet is down for long.
