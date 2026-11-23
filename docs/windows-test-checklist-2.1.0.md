# E-LIKAS desktop 2.1.0: Windows test checklist

For whoever tests the 2.1.0 installer on Windows before it goes to the
barangays. Tick each line; write what happened next to anything that fails.

**Use the practice events** ("Typhoon Drill", active, and "Incoming Typhoon",
monitored) on the real server, or a staging server, never a real event. You
need: one barangay official account, one CSWD account, a second Windows
computer (or a second Windows account) for part 9, a USB drive, and a way to
switch the internet off (unplug, or turn off Wi-Fi).

## 0. Install and upgrade

- [ ] On a computer with **2.0.2** and at least one record waiting to be sent, note
      the number on the Sync page, then install 2.1.0 over it.
- [ ] The app opens on "Update required"; press Update. It finishes without an error.
- [ ] The same number of records waits on the Sync page; they are the same records.
- [ ] `%APPDATA%\E-LIKAS\storage\app\backups` has `before-2.1.0-encryption-….sqlite.enc`.
- [ ] Account menu → Data and security says the key is protected with this Windows account.
- [ ] Opening the database file (`nativephp.sqlite` in the app's data folder) with any SQLite
      viewer shows no readable names in `families`, `ec_board_entries` or `server_snapshots`.
- [ ] Account menu → About says 2.1.0.
- [ ] A fresh install on a computer that never had E-LIKAS also works.

## 1. Compatibility

- [ ] No "DEMO" badge or label anywhere: top bar, event switcher, EC Board, Monitoring,
      Final counts, notifications.
- [ ] A center at about 80% shows **Nearly full** (amber); from 90% **Full** (red); a
      center with unknown capacity never shows either.
- [ ] Times read like "Nov 19, 1:44 AM" (Philippine time); waits read like "2 hours 30 minutes";
      counts over 999 have commas; "1 family", "2 families".
- [ ] Doing something the server refuses because of the event's state shows the slate
      lock box with the server's words, not a red error.

## 2. Event lifecycle (as the official)

- [ ] Switch to **Incoming Typhoon** (monitored). Dashboard, EC Board, each center, Private
      houses, Returning families and Transfers show the lock: "… is being monitored. … opens
      when CSWD starts the evacuation." No Add evacuee, no Transfer.
- [ ] "Families are arriving" with a note: CSWD's bell gets it. Offline it says it needs a connection.
- [ ] Switch to **Typhoon Drill** (active): "Registration is open" on the dashboard and EC Board.
- [ ] Log out and in again: the event is back to the default (the active one).

## 3. Families to evacuate

- [ ] Sidebar → Families to evacuate. Add 5 families **with the keyboard only**: Tab between
      fields, Enter adds, the purok stays selected and the cursor is back in First name.
- [ ] Search past events, add one: head and members come from the old record.
- [ ] Tick Flood on a purok, Save marks: it moves to the top with "High risk: Flood".
- [ ] On Incoming Typhoon: listing works, Check in is not offered (the lock says so).
- [ ] On Typhoon Drill, **offline**: Check in one family at a center and one at a private
      house. Both show "waiting to sync"; the center's count goes up. Back online: they sync,
      and the CSWD web shows them as evacuees. Checking in the same family again is refused.

## 4. Inside a center

- [ ] Each family under Families here has Transfer and Check out. Transfer (online) opens the move
      for that family; offline it is disabled. Check out works offline and syncs later.
- [ ] A center at 90% or more shows "Ask another center for space"; it lets you choose families,
      then shows centers with room.

## 5. Final count

- [ ] Sidebar → Final count shows the count, late entries, and the checks.
- [ ] Submit: the dialog lists the figures and has **Review first** / **Submit**. Submitting
      works online and the status becomes "Waiting for CSWD". **Note whether the server accepts
      it** (the submit path needs confirming, see the release notes).
- [ ] When CSWD submits for the barangay on the web, the page shows "Submitted by CSWD on
      behalf of …. Reason: …".
- [ ] On Incoming Typhoon the page shows the lock and no Submit.

## 6. Relief boxes (official)

- [ ] Sidebar → Relief boxes shows the round, Need (with "Based on the registered count, not
      yet validated" until the count is validated), Received, Given and Left to give.
- [ ] Families are grouped per purok; "Priority: Infant / Pregnant / lactating / PWD / senior /
      Child- or single-headed" labels show; one family has **Next in line** and the blue button.
      "By name" sorts inside each purok.
- [ ] A family from another barangay staying in your center shows "From <barangay>" and can get
      a box. Your own family staying in another barangay's center is only under "Staying in
      another barangay", with no Give button.
- [ ] Give a box to a family that is not next while higher-priority families wait: the question
      "Give a box to this family? N higher-priority families have not received a box yet.
      Continue?" comes first; Cancel gives nothing.
- [ ] **Offline**: give 3 boxes. Each shows "Given, waiting to sync", Left to give goes down and
      Next in line moves on. Giving the same family twice is refused. When Left to give reaches 0,
      no more Give buttons. Back online: they sync; on the web each family has one box only.
- [ ] Unplug in the middle of a sync, then sync again: still one box per family on the web.
- [ ] On Incoming Typhoon (monitored): the lock, no Give buttons.

## 7. Print and download (official)

- [ ] Sidebar → Print and download. For each file: the warning "This file contains personal
      information. Keep it secure." comes first; Windows' Save dialog opens; save to the
      Desktop and to the USB drive; Cancel saves nothing.
- [ ] EC Information Board PDF and Excel, family list Excel, relief distribution sheet PDF (signature
      lines), families to evacuate PDF and Excel: each opens (Edge for PDF, Excel/LibreOffice),
      shows the right data, and says "Generated offline, as of …. May not include records not yet synced."
- [ ] Do the same **offline**: every file still works.
- [ ] "Files saved from this computer" lists each one with where it went.
- [ ] The CSWD account has no Print and download.

## 8. Data protection

- [ ] Leave the app alone for 15 minutes: it shows "Signed out after 15 minutes without
      activity". Sign in again **offline** with the password: back where you were, records kept.
- [ ] A wrong password is refused; after 5 wrong ones the box is disabled for 15 minutes.
- [ ] Data and security → set 5 minutes; it signs out after 5. Put the computer to sleep for
      longer than the time: on waking it asks to sign in.
- [ ] "Sign in as someone else" asks first when records are waiting.
- [ ] Close the event on the server (staging only) with nothing waiting: after Refresh reference
      data its families and boards are gone from Data and security → Closed events; with a record
      still waiting, it stays.
- [ ] Copy the database file and the key file to another Windows account or computer: the app
      there cannot read them (expected: "could not be opened with this Windows account").

## 9. Export and import waiting records

- [ ] Offline, with records waiting: Data and security → Export, choose a passphrase twice, save
      to the USB drive. Opening the `.elikas` file in Notepad shows no names.
- [ ] On the second computer, signed in as **the same account**, with reference data refreshed:
      Import with the passphrase → they appear on its Sync page. Sync there.
- [ ] Back on the first computer, go online and sync: the server keeps each record **once**
      (check the web: no duplicate families or evacuees).
- [ ] Importing the same file again adds nothing. A wrong passphrase, or another account,
      imports nothing and says why.

## 10. Account and system

- [ ] Sign in with the username (e.g. `brgy.tuburan`) instead of the email.
- [ ] An account whose password the administrator reset: after signing in, only "Choose your own
      password" is reachable until it is changed (10+ characters). Then everything works, and the
      next offline sign-in uses the new password.
- [ ] Five wrong passwords at sign-in: "Account locked" with the server's time.
- [ ] Administrator switches maintenance on: sign-in shows the message; while signed in, a
      maintenance banner shows and records wait. Switch it off: they sync.
- [ ] Administrator adds a system notice: the banner shows within about 5 minutes.
- [ ] Account menu → Report a problem with a screenshot: the administrator sees it with the
      picture; the reply shows on the page and in the bell.
- [ ] Administrator → Devices and sync shows this computer with version 2.1.0, records waiting
      and failed, and last sync. Signing the device out there sends the app to sign-in.

## 11. Layout

- [ ] At 1366x768 and 1920x1080 no page scrolls sideways, and nothing but the page has its own
      scrollbar. Compare with `docs/screenshots/2.1.0/`.
