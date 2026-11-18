# Event switcher, evacuation orders, private houses, roles, late entries: API changes for the desktop and Flutter apps (November 2026)

All endpoints are under `/api/v1`, with the usual `Authorization: Bearer
<token>` and the usual body `{ "success", "message", "data" }` (refusals:
`{ "success": false, "message", "errors" }`). Permissions come with
`POST /auth/login` and `GET /auth/me` (`data.permissions`).

The last section, **What the apps must change**, is the checklist.

---

## 1. The event switcher (the selected event)

Several events can be open at once (for example one active and one in
monitoring). Each user works on one event at a time, remembered on the
server, so the web and the apps share it.

- `GET /auth/me` and the login response carry
  `selected_evacuation_event`: `{ id, name, event_type, typhoon_category,
  status, is_demo, start_date }`, or `null` when no event is open (always
  `null` for the administrator).
- With nothing chosen, the server picks it: the only open event when exactly
  one is open, else the current one (a real event before a demo one, an
  active one before one in monitoring, the newest).

### GET /selected-event (permission `operations.access`)

```json
{
  "selected": { "id": 79, "name": "DEMO - Typhoon Drill", "event_type": "typhoon", "typhoon_category": "Typhoon", "status": "active", "is_demo": true, "start_date": "2026-11-17" },
  "chosen": false,
  "open_count": 2,
  "open_events": [ { "...same fields..." } ],
  "closed_events": [ { "...same fields..." } ]
}
```

`chosen` is `false` when the server picked the event. Closed events can be
chosen to look back at them; everything about a closed event is read-only.

### PUT /selected-event (permission `operations.access`)

Body: `{ "evacuation_event_id": 80 }`, or `null` to let the server pick
again. 200 with the same body as the GET; message `Now working on "…".`
422 for an unknown id.

When an event is closed, the users working on it go back to "picked by the
server". When a new event is created, users who were looking at a closed
event go back too.

### Endpoints that use it

Every endpoint that, with no `evacuation_event_id`, used "the current event"
now uses the user's selected event: `GET /gis/situation`,
`/gis/barangays/{id}`, `/gis/barangays/{id}/situation`,
`/gis/barangays/{id}/destinations`, `/gis/priority`, `/monitoring/{section}`,
`/dashboard/city`, `/dashboard/barangay`, `/preparedness/summary`,
`/private-house-families`. Naming `evacuation_event_id` still wins.
`GET /families` and the other Evacuees endpoints keep their old default
(every open event) when no event is named: send the selected event's id.

---

## 2. Evacuation orders (GIS barangay status)

`POST /gis/barangay-status` -- a preemptive or forced evacuation is now
recorded as an **order**, with who ordered it and when (CSWD only,
permission `situation.set_evacuation`):

```json
{
  "evacuation_event_id": 80, "barangay_id": 13, "status": "forced_evacuation",
  "ordered_by": "city_mayor",
  "ordered_by_other": null,
  "ordered_at": "2026-11-17T07:00:00Z",
  "note": "optional"
}
```

| Field | Rule |
| --- | --- |
| `ordered_by` | **required** for `preemptive_evacuation` / `forced_evacuation`: `city_mayor`, `cdrrmo` or `other`. 422 "Say who ordered the evacuation." |
| `ordered_by_other` | required when `ordered_by` is `other` (max 100). 422 "Name who ordered the evacuation." |
| `ordered_at` | optional (now); ISO time, not in the future. 422 "The order time cannot be in the future." |

The 201 message for an order is `Forced evacuation order recorded for
Binatagan.` Barangay officials still set `normal`, `watch` and
`flooding_reported` only.

Every status (in `/gis/situation`, `/gis/barangays/{id}` `status` and
`history`, `/gis/barangays/{id}/situation`, the POST answer) has a new key:

```json
"order": {
  "ordered_by": "city_mayor",
  "ordered_by_label": "the City Mayor",
  "ordered_at": "2026-11-17T07:00:00+00:00",
  "summary": "Forced evacuation ordered by the City Mayor at 3:00 PM, recorded by Ana Cruz"
}
```

`null` for a status that is not an order. Show `summary` (local time,
Asia/Manila) under the status. Ask for confirmation first: "Record a forced
evacuation order for Binatagan in Typhoon Rolly?". Activity log action:
`situation.evacuation_ordered`.

---

## 3. Private houses on the EC Board

Families staying with relatives or friends (stays with
`displacement_type = outside_center`, no center).

### GET /private-house-families (permission `operations.access`)

Query: `evacuation_event_id` (default: the selected event), `barangay_id`
(optional; an official may only ask for their own).

```json
{
  "evacuation_event": { "id": 79, "name": "DEMO - Typhoon Drill", "status": "active", "...": "..." },
  "barangays": [ { "barangay": { "id": 13, "name": "Binatagan" }, "families": 5, "persons": 18 } ],
  "families": [
    { "id": 3601, "label": "Rosa Santos", "member_count": 3, "host_name": "Carmen Reyes", "host_address": "Zone 3",
      "host_purok": { "id": 4, "name": "Purok 2" }, "is_4ps_beneficiary": true, "incomplete": true, "created_at": "…" }
  ]
}
```

`families` is only there with `barangay_id`. Only families with someone
still checked in count.

### POST /barangays/{barangay}/private-house-families (permission `evacuees.manage`)

The quick "Add family". Officials only, their own barangay (403 otherwise;
CSWD gets 403). Idempotent with `client_ref` (a UUID).

```json
{
  "evacuation_event_id": 79,
  "head": { "first_name": "Rosa", "last_name": "Santos", "sex": "female", "age": 44 },
  "members": [ { "first_name": "Lito", "last_name": "Santos", "sex": "male", "age": 9 } ],
  "host_name": "Carmen Reyes",
  "host_address": "Zone 3, near the chapel",
  "host_purok_id": null,
  "is_4ps_beneficiary": true,
  "client_ref": "8f0e7c1a-1111-4222-8333-944455566677"
}
```

`host_name` required; `host_address` or `host_purok_id` (a purok of that
barangay); `age` 0-120 is kept as an age group until a birth date is
entered on the Evacuees page (the members are "details pending"). A closed
event: 422. 201:
`{ "id", "label", "member_count", "evacuation_event" }`, message "Family
added to private houses. Complete their details on the Evacuees page."

The old `POST /families/register` stays for the apps (officials only now,
see 4).

---

## 4. Roles: CSWD reads evacuees; barangay officials manage them

New permissions:

| Permission | Roles | What |
| --- | --- | --- |
| `evacuees.manage` | barangay officials | register, edit, check out, remove; EC Board Add evacuee; private-house Add family |
| `evacuees.transfer` | CSWD, officials | move a family to another center |
| `evacuees.request_correction` | CSWD | ask the barangay to correct a family |

`families.re_enroll` is now **officials only** (it registers).

These now answer **403 for CSWD** (permission `evacuees.manage`):
`POST /families/register`, `POST /families/{id}/members`,
`PATCH /families/{id}/household`, `PATCH /evacuees/{id}`,
`POST /evacuees/{id}/check-out`, `DELETE /evacuees/{id}`,
`POST /families/bulk-check-out`, `POST /families/{id}/deletion-preview`,
`POST /families/{id}/delete-members`, `DELETE /families/{id}`,
`POST /evacuation-centers/{id}/evacuees`, `PUT /evacuation-centers/{id}/quick-count`,
`POST /barangays/{id}/private-house-families`; and (permission
`families.re_enroll`) `GET /evacuation-centers/{id}/returning-families`,
`POST /evacuation-centers/{id}/re-enroll`. Reading stays open to CSWD.

### Removing needs a reason

`DELETE /evacuees/{id}`, `POST /families/{id}/delete-members` and
`DELETE /families/{id}` now need `reason` (string, max 500) in the body.
422 `errors.reason`: "Say why this record is being removed." It goes into
the activity log ("… Reason: Registered twice").

### Transfer: PATCH /families/{id}/evacuation-center (permission `evacuees.transfer`)

Body: `{ "evacuation_center_id": 12, "reason": "Own school is full" }`.

- CSWD: any center of any barangay; `reason` **required** (422 "Say why
  the family is being transferred.").
- Officials: their own families, to a center **of their own barangay**
  (403 otherwise); `reason` optional.

### Request correction: POST /families/{id}/correction-requests (permission `evacuees.request_correction`)

Body: `{ "note": "Birth date of the head looks wrong." }` (required, max
1000). Sent as an in-app notification to every active barangay official
account of the family's barangay (`GET /notifications`: title
"Correction requested: <family>", message "<CSWD name> (CSWD): <note>",
`href` `/families/{id}`, `family_id`). 201 `{ "sent_to": 2 }`; 422 when the
barangay has no active official account. Log action
`family.correction_requested`.

---

## 5. Evacuees figures and each family's count status

`GET /families/stats` and every row of `GET /families/barangay-summary` add:

```json
"families_in_centers": 435, "persons_in_centers": 1503,
"families_in_private_houses": 43, "persons_in_private_houses": 168,
"late_families": 6, "incomplete_families": 1
```

(by the latest stay: a family by its first member's, a person by their own).

`FamilyResource` (every family in `/families`, `/families/{id}`, …) adds:

- `count_status`: `{ key, label, as_of, submission_id }` where `key` is
  `validated` ("Validated - report as of <as_of>"), `submitted`
  ("Submitted - waiting for CSWDO"), `returned`, `late_entry` or
  `not_submitted`.
- `incomplete_details`: a member still lacks a name, sex or birth date
  (when members are loaded).

`GET /families` filters: `late_only=1`, `incomplete_only=1`.

Show only what is true about a family ("Head: female", a "Single-headed"
tag only when `is_single_headed` is `true`; nothing for `false` or `null`).

## 6. Events list on one basis

`EvacuationEventResource` (`GET /evacuation-events`, `/{id}`) adds
`families_now` (families with someone checked in) and `persons_now` (persons
checked in). Show open events as "477 families - 1,669 persons now".
`total_families_displaced` / `total_persons_displaced` are unchanged.

---

## 7. Final counts and late entries

Registration is never locked. A final count is a snapshot with its time.
Families and persons added or removed after a barangay's latest submission
are **late entries**: not in the validated count or DROMIC until a
submission that includes them is validated (they can still get relief
boxes). A validated submission never changes; later entries go into the
next one.

`late_entries` (null when the barangay has not submitted):

```json
{ "since": "2026-11-17T06:00:00+00:00", "submission_id": 41, "submission_status": "validated",
  "families": 3, "persons": 11, "removed_families": 0, "removed_persons": 0, "total": 3 }
```

- `GET /count-submissions/computed` adds `late_entries` and `checks`.
- `GET /count-submissions/barangays` (CSWD) adds `late_entries` per row and
  `late_entries_total`.
- `GET /count-submissions/{id}/checks` (CSWD, `count_submissions.review`):
  `{ submission, computed, late_entries, checks }` for the Validate dialog.
- `GET /evacuation-centers/{id}/quick-count` adds `late_entries` (families
  at that center registered after their barangay's latest submission).
- `GET /monitoring/overview`: `figures.late_entries`, and an `attention`
  item `kind: late_entries` ("N late entries pending, not yet in a final
  count").

`checks` ("system checks" -- warnings only, never a block):

```json
{ "incomplete_families": 1, "incomplete_examples": [ { "id": 3601, "label": "Rosa Santos" } ],
  "possible_duplicates": 1, "duplicate_examples": [ { "name": "Rosa Santos", "date_of_birth": "1980-05-01", "count": 2, "family_ids": [3601, 3644] } ] }
```

Confirm dialogs:

- Barangay submit: families and persons (in centers, in private houses), the
  checks, "After this, new evacuees are marked as late entries." Buttons
  **Review first** / **Submit**.
- CSWD validate: submitted figures against the system's count now (the late
  entries), the checks, "This becomes the official count."
- CSWD "Submit for this barangay" (`POST /barangays/{id}/count-submissions`):
  the system's count at that moment, labelled "Submitted by CSWD for the
  barangay" (`submitted_on_behalf: true`).
- Return to barangay needs a note (unchanged).

---

## 8. Forecast (Predictive analytics, CSWD)

`GET /preparedness/forecast`, for a storm category (share method):

- each barangay adds `families_low`, `families_high`, `persons_low`,
  `persons_high` (the same shares x the smallest and the largest past
  storm's city total), `plan_families` (= high) and `above_seen` (the
  forecast is above the highest it has ever had: show "Above anything seen
  before");
- `totals` adds `families_low/high`, `persons_low/high`; `range_from:
  { low: {id, name, families}, high: {…} }`;
- `needs` adds `plan_for: { families, persons }` (the high figure, which
  boxes, water, facilities and capacity now use), `boxes.needed_low`,
  `water.litres_low`, `capacity: { persons_high, persons_low,
  capacity_persons, short, short_low, centers_without_capacity }`;
  `centers_short` is `null` when no center expected to be used has a
  facilities checklist: show "Unknown - no facilities checklist", never "0".

`GET /preparedness/forecast-accuracy`: `with_actual_total` is renamed
`shares_only_reference` (it uses the left-out storm's own total: reference
only); `folds[].category_total_from` adds `basis` (`same_category`,
`nearest_stronger`, `nearest_weaker`) and `label`; `points[].predicted` are
the forecast's own predictions. See docs/forecast-method.md.

`GET /monitoring/readiness` `forecast` adds `families_low`, `families_high`
and the same per barangay.

Demo events are never history, never in the check and never in real
reports (`POST /reports/*` answer 422 for a demo event, as before).

---

## 9. Demo data (server commands, not API)

- `php artisan demo:reset --force`: removes every demo event and seeds
  "DEMO - Past Typhoon" (closed), "DEMO - Typhoon Drill" (active) and "DEMO -
  Incoming Typhoon" (monitoring).
- `php artisan demo:mark --event-ids=19,20 --force`: marks test events as
  demo (name gets "DEMO - ") so purge/reset remove them.

---

## What the apps must change

### Desktop app (staff: CSWD and barangay officials)

1. **Event switcher.** Read `selected_evacuation_event` from login/`/auth/me`
   (or `GET /selected-event`), show "Event: <name>" in the top bar with the
   open and closed events, and change it with `PUT /selected-event`. Use its
   id for every event-based screen (GIS, Monitoring, EC Board, Evacuees,
   Final counts, Relief boxes, Predictive analytics, DROMIC) instead of a
   per-screen event picker; registration forms say "Saves to: <event>". A
   closed event is read-only.
2. **Evacuation orders.** Send `ordered_by` (and `ordered_by_other`,
   `ordered_at`) with a preemptive/forced status, after a confirm dialog;
   show `status.order.summary`. Offer officials only Normal / Watch /
   Flooding reported. Show "Status for <event>" in the barangay panel.
3. **Roles.** Hide register, edit, check-out, remove, depart and bulk
   actions, EC Board Add evacuee and Returning families for CSWD (they now
   get 403); give CSWD "Transfer to another center" (reason required) and
   "Request correction" (`POST /families/{id}/correction-requests`).
   Officials: transfer only to their own barangay's centers.
4. **Removing** a member or family: ask for a reason and send `reason`
   (now required).
5. **Private houses.** Add the barangay "Private houses" card with the quick
   Add family (`POST /barangays/{id}/private-house-families`, with
   `client_ref`) and list (`GET /private-house-families`). Remove any
   "Register a family" form for CSWD.
6. **Evacuees.** Show "In centers" / "In private houses" from the new stats
   keys, each family's `count_status`, filters `late_only` /
   `incomplete_only`, the "N families have incomplete details" notice, and
   only true family facts.
7. **Final counts.** Show `late_entries` ("N late entries, not yet in your
   final count" with "Submit updated count"; a Late entries section for
   CSWD), the confirm dialogs with `checks` (Review first / Submit), and
   `GET /count-submissions/{id}/checks` before CSWD validates. Show the
   late-entries badge from the quick-count's `late_entries` on the EC Board.
8. **Events list:** use `families_now` / `persons_now` for open events.
9. **Forecast** (if shown): ranges, "Plan for", "Above anything seen
   before", needs with low figures, `centers_short: null` as unknown, the
   renamed accuracy keys.
10. **Offline queue:** queued removals without `reason`, CSWD registrations
    and CSWD evacuation orders without `ordered_by` will now be refused (422
    / 403); show the server's message and let the user fix or drop them.

### Flutter app

- **Resident features** (public endpoints): no change.
- **Staff features**, if the build has them: the same items as the desktop
  app, at least 1 (event from `selected_evacuation_event` / `PUT
  /selected-event`), 2 (`ordered_by` on orders), 3 (CSWD read-only, 403s
  handled), 4 (`reason` on removals), 5 (private-house quick add for
  officials) and 7 (`late_entries` and the submit dialog).

---

The next update (center capacity, transfers between barangays) is in
docs/apps-api-2026-11-capacity-transfers.md, with its own "What the apps
must change".
