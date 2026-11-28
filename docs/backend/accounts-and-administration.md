# Accounts, the administrator's role, and CSWD acting for a barangay

How E-LIKAS handles staff accounts and what each role may do on behalf of
another, as of November 23, 2026. The API details for the apps are in
`docs/apps-api-2026-11-23.md`.

## 1. Barangay official accounts

Every one of Ligao's 55 barangays should have a barangay official account,
because the official registers the barangay's evacuees and sends its final
count. On User management, "Create accounts for barangays without an
official" makes one account for every barangay that has no official
account at all:

| | |
| --- | --- |
| Username | `brgy.<barangay>`, e.g. `brgy.tula-tula-pequeno` |
| Email | `brgy.<barangay>@e-likasligao.online` |
| Name | "Tula-tula Pequeño Barangay Official" |
| Password | a temporary one, e.g. `tula-tula-pequeno-Hn5-cV8s-K1m` |

Rules:

- Existing accounts are never changed. A barangay whose only official
  account is inactive or suspended gets no new one; the list shows it so
  the administrator can reactivate that account or reset its password.
- A username or email already used by another account is skipped and
  reported, never overwritten.
- The temporary passwords are shown once, in the dialog that follows
  (with Copy and Download CSV, made in the browser). They are stored only
  as hashes, never written to the logs, never emailed and never shown
  again; a forgotten one is replaced with "Reset password".

## 2. Temporary passwords and signing in

- Format: `<barangay-slug>-XXX-XXXX-XXX`, the three groups drawn from
  upper- and lower-case letters and digits with a cryptographically
  secure generator (`random_int`). Accounts other than barangay officials
  use their username or email name as the prefix.
- A new account, the barangay official accounts and every "Reset password"
  get a temporary password, and the account must choose its own before it
  can do anything else (`ELIKAS_REQUIRE_PASSWORD_CHANGE=false` switches
  this off for a demonstration). A reset also signs the account out on
  every device and lifts a lock from failed sign-ins.
- Staff sign in with their email or their username.
- Five failed sign-ins in a row lock the account for 15 minutes; the
  administrator can unlock it at once. Every failure, lock, unlock, reset
  and password change is a security event in the activity logs.

## 3. What the administrator does, and does not

The administrator handles technical problems only. The role has no
operational permission, so it never sees or changes evacuee details,
counts, relief, alerts or reports (the API refuses with 403, and tests
check every route). Its tools:

| Area | What it does |
| --- | --- |
| Users and access | Create and deactivate accounts, the barangay official accounts, reset passwords, unlock, sign out everywhere (a lost phone), change a role or barangay (logged with the old and new values), accounts not used for 30+ days |
| Devices and sync | Each phone or computer an account uses the apps on: app version, last seen, last sync, records waiting or failed (counts only, from the apps' headers); sign one device out |
| Support requests | Problems reported by CSWD and officials (what happened, the page, an optional screenshot), with New / In progress / Resolved and a reply; the reporter is notified |
| System | Disk space, database size, last backup age (a warning after 24 hours), whether the scheduler runs, errors in the last 24 hours, SMS sent and failed and the provider's balance; latest app versions; maintenance mode with a message; system notices with a start and an end |
| Logs | Filters, security events, CSV export |
| Backups | Make and download backups. Restoring one is a server command only |

The one documented exception remains: backups hold all personal data, and
only the administrator may make or download them.

## 4. CSWD acting for a barangay

CSWD stays read-only on individual evacuees: it never registers, edits or
removes a person. It may act for a barangay in three ways, each with a
reason, each logged, and each told to the barangay's official when there
is one.

| When | What CSWD may do |
| --- | --- |
| The barangay has no official account | Submit its final count as counted by the system (validated at once); confirm relief deliveries and give boxes for it |
| The barangay has an official, whenever CSWD decides | Submit its final count on its behalf, with a written reason and a confirm. Saved as "Submitted by CSWD on behalf of <barangay>. Reason: ...". The dialog only informs (when the barangay was last reminded, the official's last activity). The official can still send an updated count |
| The barangay has no official, or registered no evacuees in the event | Encode its paper EC Board: per center, families, persons, age groups by sex and sectoral groups, with photos of the paper as proof (nothing is read from the photos). The totals can become its final count and DROMIC reports them. Relief for such a barangay is recorded as boxes given in total |

## 5. Practice events and accounts

`demo:purge`, `demo:reset` and "Delete practice event" never touch an
account: the purge compares every account before and after inside its
transaction and rolls back if anything changed (tests check all three).
Practice data is recorded as each barangay's official when it has one
(registrations, Watch / Flooding statuses, transfer and facility
requests, final counts); evacuation orders stay CSWD's.
