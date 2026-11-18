# Center capacity and transfers: API reference for the desktop and mobile apps (18 November 2026)

This is the complete reference for two server changes that the desktop
(NativePHP) and mobile (Flutter) apps must handle:

1. **Center capacity:** each center now says where its capacity figure comes
   from (`capacity_source`, `capacity_basis`).
2. **Transfers:** when a center is full, families can move to another
   center, either directly or through a request that the other barangay
   accepts or declines.

docs/apps-api-2026-11-capacity-transfers.md has the shorter overview and the
"What the apps must change" checklist. docs/center-capacity.md explains how
the estimates were made.

## 0. Conventions

- Base URL `/api/v1`. Every call except `/public/*` needs
  `Authorization: Bearer <token>` and `Accept: application/json`.
- Successful responses: `{ "success": true, "message": "…", "data": … }`.
- Error responses always carry `message`, which you can show as it is. Most
  also carry `errors` (field → list of messages). There are two shapes:
  - From a controller or middleware:
    `{ "success": false, "message": "…", "errors": { … } | null }`
  - From validation or a service rule (no `success` key):
    `{ "message": "…", "errors": { "field": ["…"] } }` for 422, or
    `{ "message": "…" }` for 403.

  Read `message` and, when it is there, `errors`. Don't depend on
  `success` being present on errors.
- Common errors on every endpoint below:

  | Status | When | `message` |
  |---|---|---|
  | 401 | no or expired token | `Unauthenticated.` |
  | 403 | the role lacks the permission | `You are not authorized to perform this action.` |
  | 403 | suspended or deactivated account | `This account is not active. Contact the system administrator.` |
  | 422 | the event is closed (writes only) | `"<event name>" is closed. A closed event is read-only.` (also under `errors.evacuation_event_id`) |
  | 422 | no open event at all (writes only) | `No open event yet. CSWDO opens an event when a threat is announced.` |

## 1. Permissions per role

| Call | CSWD (`cswd_personnel`) | Barangay official | Administrator / resident |
|---|---|---|---|
| Read `capacity_source` / `capacity_basis` (center, map, monitoring endpoints) | yes | yes (own barangay's data as before) | 403 (residents: only `/public/*`) |
| `POST /evacuation-centers/capacity-import` | yes (`centers.import_capacity`) | 403 | 403 |
| `PATCH /families/{id}/evacuation-center` (direct move) | any center, `reason` required | own barangay's families, own barangay's centers only | 403 |
| `GET /transfer-requests/suggestions` | 403 | yes (`transfers.request`) | 403 |
| `POST /transfer-requests` | 403 (CSWD moves directly instead) | yes (`transfers.request`) | 403 |
| `GET /transfer-requests` | every request | requests to and from their barangay | 403 |
| `POST /transfer-requests/{id}/accept` and `/decline` | any pending request (`transfers.manage`) | pending requests **to** their barangay (`transfers.receive`) | 403 |
| `GET /dashboard/city` `actions.transfer_requests_pending` | yes | n/a (city dashboard is CSWD's) | 403 |
| `GET /dashboard/barangay` `todo` | n/a | yes | 403 |

`GET /auth/me` and login return the account's `permissions` list. It now
includes `transfers.request` and `transfers.receive` for officials and
`transfers.manage` for CSWD, plus `centers.import_capacity` for CSWD. Use
the list to show or hide buttons; don't hard-code the role.

## 2. Online-only calls

**All of these need a live connection. Do not queue them offline and send
them later:**

| Call | Why |
|---|---|
| `GET /transfer-requests/suggestions` | Room left is computed from who is inside each center right now. A cached list sends families to a center that has since filled up. |
| `POST /transfer-requests` | The server checks the families are still at one center, not already in a waiting request, and that the target is open. It also notifies the receiving barangay and starts the 30-minute clock. It has no `client_ref` duplicate protection. |
| `POST /transfer-requests/{id}/accept` and `/decline` | One answer per request; whoever answers first wins (a second answer gets 422). Accepting moves the families' check-ins at that moment. |
| `PATCH /families/{id}/evacuation-center` | Moves the family's current check-ins and updates the receiving center's counts at that moment. |
| `POST /evacuation-centers/capacity-import` | All-or-nothing write that is logged. Do the dry run and the real import in one sitting. |

Without a connection, disable these buttons and say "Needs a connection".
Don't save them for later.

**These can be cached for offline display.** Show the time the data was
fetched and refresh when back online:
- `capacity_source` / `capacity_basis` on centers
- the dashboard `todo` and `capacity` counts
- the last `GET /transfer-requests` list. Hide Accept and Decline while
  offline, because `may_decide` can be out of date.

**Retrying after a lost response:** none of these calls take `client_ref`.
If a request timed out and you don't know whether it went through:

- `POST /transfer-requests`: call `GET /transfer-requests?status=pending`
  first. If the request is there, it was sent. A blind retry gets 422 `A
  family is already in a transfer request waiting for an answer.`
- accept or decline: call `GET /transfer-requests` again and read the
  request's `status`. A retry gets 422 `This request was already answered.`
- `PATCH /families/{id}/evacuation-center` is safe to repeat. Moving a
  family to the center it is already at changes nothing.

## 3. Center capacity

### 3.1 Fields

Each center carries:

| Field | Values | Meaning |
|---|---|---|
| `capacity_source` | `official` | entered by an official (and approved), or imported by CSWD |
| | `estimated` | filled once by the server from the 2025 peak, or the median capacity of the same type of center |
| | `unknown` | no capacity at all |
| `capacity_basis` | `peak_2025`, `type_median`, `null` | how an estimate was made (always `null` unless `estimated`) |

These endpoints return the fields:

| Endpoint | Where in the response |
|---|---|
| `GET /evacuation-centers`, `GET /evacuation-centers/{id}` and every other endpoint returning a center resource | each center object |
| `GET /gis/map-data` | each center |
| `GET /gis/barangays/{id}` | `centers[]` |
| `GET /gis/centers/{id}` | the center |
| `GET /gis/barangays/{id}/destinations` | each row (with `capacity_persons`, `remaining_persons`, `state`) |
| `GET /monitoring/centers` | `centers[]` (with `inside`, `capacity`, `percent`) |
| `GET /public/evacuation-centers`, `GET /public/evacuation-centers/{id}` | `capacity_source` only (no `capacity_basis`) |

Example (center resource, excerpt):

```json
{ "id": 71, "name": "LWCES (B)", "capacity_families": 30, "capacity_persons": 112,
  "capacity_source": "estimated", "capacity_basis": "peak_2025" }
```

### 3.2 How to show it

| Source | Show | Tooltip |
|---|---|---|
| `official` | `48 / 112` | none |
| `estimated` | `48 / 112 est.` | `peak_2025`: "Estimated from the 2025 peak"; `type_median`: "Estimated: median of the same type of center" |
| `unknown` | `48 / – (capacity unknown)` | none. Never show 0 or a blank, and never show it as full. |

Rules the server already applies (keep the app consistent with them):
- A center is **full** at 90% of `capacity_persons`, whether the figure is
  official or estimated.
- A center with an **unknown** capacity is never full and never "near full".
  In destinations and transfer suggestions it comes last.
- When an official saves a capacity, it becomes `official` and
  `capacity_basis` becomes `null`. For an approved center this happens once
  CSWD approves the change request. Clearing both capacities makes it
  `unknown`. The app sends nothing new; the server sets the source.

### 3.3 Counts on the dashboards

`GET /dashboard/city` (CSWD), `GET /monitoring/readiness` and `GET
/monitoring/centers` add:

```json
"capacity": { "official": 2, "estimated": 149, "unknown": 0, "total": 151 }
```

These count centers that are not rejected. On the monitoring endpoints they
cover the barangay being looked at; for CSWD with no barangay filter, the
whole city.

`GET /dashboard/barangay` (official) adds `todo`. It is an empty list when
every center has an official capacity:

```json
"todo": [{
  "kind": "center_capacity",
  "text": "3 of your 4 centers have no capacity or only an estimate",
  "detail": "1 unknown, 2 estimated. Enter each center's capacity on its page.",
  "href": "/evacuation-centers?filter=attention",
  "counts": { "official": 1, "estimated": 2, "unknown": 1, "total": 4 }
}]
```

Show `text` and `detail` as they are, and open the centers list from the
item. Treat `href` as the web path; the app opens its own centers screen.
Ignore any `kind` the app doesn't recognise, because more may be added.

### 3.4 `POST /evacuation-centers/capacity-import` (CSWD)

The web app has this screen (Center approvals → Import capacities). The
apps don't need to build it; it is here for completeness.

Request: `multipart/form-data` with `file` (CSV, at most 1 MB), or JSON
with `csv` (the file's text). Optional `dry_run` (boolean, **default
true**).

```
center,barangay,capacity_families,capacity_persons
Binatagan Barangay Hall,Binatagan,40,150
```

200 (dry run, or the real import):

```json
{ "success": true,
  "message": "Dry run: 1 center would get an official capacity. Nothing was changed.",
  "data": { "dry_run": true, "rows": 1, "valid": 1, "changes": 1, "errors": [],
    "preview": [{ "row": 2,
      "given": { "center": "Binatagan Barangay Hall", "barangay": "Binatagan", "capacity_families": "40", "capacity_persons": "150" },
      "center": { "id": 12, "name": "Binatagan Barangay Hall", "barangay": "Binatagan" },
      "current": { "capacity_families": 28, "capacity_persons": 104, "capacity_source": "estimated" },
      "new": { "capacity_families": 40, "capacity_persons": 150 },
      "changes": true, "errors": [] }] } }
```

With `dry_run=false` and no errors, the message is `1 center now have an
official capacity.` (the server pluralises it). The import is all or
nothing; while any row has an error, nothing is saved.

| Status | `message` | Notes |
|---|---|---|
| 422 | `Choose a CSV file.` | no `file` and no `csv` |
| 422 | `The file needs a header row (center, barangay, capacity_families, capacity_persons) and at least one row.` | `errors.file` |
| 422 | `Missing column: capacity_persons. The header is: center, barangay, capacity_families, capacity_persons.` | `errors.file` |
| 422 | `At most 1000 rows per file.` | `errors.file` |
| 200 | `Dry run: 2 errors to fix first. Nothing was changed.` | dry run with row errors, listed in `data.errors` |
| 422 | `Fix the errors first: nothing was imported.` | `dry_run=false` with row errors; the whole summary is under `errors` (`errors.errors` is the list) |

Row errors (each prefixed `Row N: `):
- `No barangay named "…".`
- `No center named "…" in <barangay>.`
- `Two or more centers in <barangay> are named "…": set their capacity on the center page.`
- `The same center is on row N too.`
- `capacity_persons must be a whole number of 0 or more (got "…").`
  (the same for `capacity_families`)
- `Give capacity_families, capacity_persons or both.`

## 4. Moving families when a center is full

There are two ways to move families:

| Case | Who | How |
|---|---|---|
| To another center **in the same barangay** | the barangay's official | direct move: `PATCH /families/{id}/evacuation-center` (4.1) |
| To **another barangay's** center | the sending barangay's official | transfer request: suggestions → `POST /transfer-requests` → the receiving barangay accepts or declines (4.2–4.5) |
| To **any** center | CSWD | direct move with a reason: `PATCH /families/{id}/evacuation-center` (4.1) |

Rules for every move:
- Families keep their **home barangay** for every count: the dashboards,
  final counts and DROMIC. A center's occupancy counts whoever is staying
  there, transferred families included.
- Until the receiving barangay accepts a request, the families stay at
  their center and are counted there.
- Accepting moves the families' current check-ins. The check-in time is
  kept, the receiving center's EC Board cumulative count goes up, and the
  move is logged.
- Ask the user to confirm before every send, accept, decline and CSWD
  move. The confirm dialog should name the families, persons, and both
  centers.

### 4.1 `PATCH /families/{id}/evacuation-center` (direct move; unchanged endpoint)

Permission `evacuees.transfer` (CSWD and officials).

```json
{ "evacuation_center_id": 141, "reason": "Covered court roof damaged." }
```

- `reason` (at most 500 characters) is **required for CSWD** and optional
  for officials.
- An official may only move their own barangay's families between their
  own barangay's centers. For another barangay's center, use a transfer
  request.
- Moves every member who is still checked in (not checked out). Logged as
  `family.evacuation_center_reassigned`, with the reason.

200: `{ "success": true, "message": "Evacuation center updated successfully.", "data": <family resource> }`

| Status | `message` | When |
|---|---|---|
| 403 | `You may not modify families outside your barangay.` | official, another barangay's family |
| 403 | `You can transfer a family only between the centers of your own barangay. CSWD can transfer it to another barangay.` | official, target in another barangay (also `errors.evacuation_center_id`) |
| 422 | `Say why the family is being transferred.` | CSWD without `reason` (`errors.reason`) |
| 422 | `That evacuation center can't be used.` | target not approved (pending is allowed for CSWD and for the center's own barangay) |
| 422 | `This family has no active evacuation records to reassign -- every member has already checked out.` | nobody checked in |

### 4.2 `GET /transfer-requests/suggestions?from_center_id=12&persons=7`

Permission `transfers.request` (officials). `from_center_id` is required and
is the center the families are at now. `persons` (0–100000) is how many
people need to move and only affects `fits`.

The response lists up to 15 approved, open centers in **other** barangays
that still have room. Full centers are left out. Rows with room are sorted
nearest first. Centers with an unknown capacity come after them, with
`capacity_unknown: true`.

```json
{ "success": true, "message": "Request successful.",
  "data": {
    "from_center": { "id": 12, "name": "LWCES (B)", "barangay_id": 13 },
    "persons": 7,
    "centers": [
      { "evacuation_center": { "id": 141, "name": "DA Building", "type": "other", "status": "open" },
        "barangay": { "id": 49, "name": "Tomolin" }, "same_barangay": false,
        "has_location": true, "latitude": 13.6512, "longitude": 123.1879,
        "distance_km": 2.4, "distance_basis": "center",
        "capacity_persons": 120, "capacity_source": "estimated", "capacity_basis": "peak_2025",
        "current_occupancy": 64, "remaining_persons": 56, "state": "available",
        "fits": true, "capacity_unknown": false },
      { "evacuation_center": { "id": 150, "name": "Tomolin Chapel", "type": "church", "status": "open" },
        "barangay": { "id": 49, "name": "Tomolin" }, "same_barangay": false,
        "has_location": false, "latitude": null, "longitude": null,
        "distance_km": 2.9, "distance_basis": "barangay_centroid",
        "capacity_persons": null, "capacity_source": "unknown", "capacity_basis": null,
        "current_occupancy": 3, "remaining_persons": null, "state": "capacity_not_set",
        "fits": false, "capacity_unknown": true }
    ] } }
```

How to show the rows:
- `fits: false` with a number: "Room for 5", in amber.
- `capacity_unknown: true`: a "Capacity unknown" tag.
- `distance_basis: "barangay_centroid"`: "about 2.9 km", because the
  center isn't on the map yet.
- `distance_km: null`: no distance.
- An empty `centers`: "No other barangay's center has space."

Errors: 422 when `from_center_id` is missing or not a center (Laravel
validation message under `errors.from_center_id`); 403 for CSWD and
administrators.

### 4.3 `POST /transfer-requests` (an official asks)

Permission `transfers.request` (officials only; CSWD gets 403 and moves
directly instead).

```json
{ "evacuation_event_id": 85, "family_ids": [5669, 5672], "to_center_id": 141, "note": "Our hall is full." }
```

| Field | Rule |
|---|---|
| `evacuation_event_id` | required; the selected event (must be open) |
| `family_ids` | required, 1–100 distinct ids; the official's own barangay's families in that event, each with someone checked in, all at the **same** center |
| `to_center_id` | required; another barangay's approved center that is not closed |
| `note` | optional, at most 500 characters; shown to the receiving barangay |

201:

```json
{ "success": true,
  "message": "Transfer request sent. The families stay at their center until it is accepted.",
  "data": <request row, 4.5> }
```

The receiving barangay's active officials get an in-app notification
(4.6).

| Status | `message` (field in `errors`) |
|---|---|
| 422 | `Choose the families to move.` (`family_ids`) |
| 422 | `Choose the center to move them to.` (`to_center_id`) |
| 422 | `That center is in your own barangay: move the families there directly.` (`to_center_id`) |
| 422 | `That evacuation center can't take transfers.` (`to_center_id`): not approved, or closed |
| 422 | `A family was not found.` (`family_ids`) |
| 403 | `You can only ask to move families of your own barangay, in the selected event.` |
| 422 | `Every family must have someone checked in at a center.` (`family_ids`) |
| 422 | `Choose families staying at the same center.` (`family_ids`) |
| 422 | `The families are already at that center.` (`to_center_id`) |
| 422 | `A family is already in a transfer request waiting for an answer.` (`family_ids`) |
| 422 | the closed-event or no-open-event message (`evacuation_event_id`) |

### 4.4 `POST /transfer-requests/{id}/accept` and `POST /transfer-requests/{id}/decline`

The route requires `evacuees.transfer`. On top of that, the server only
allows:
- an official of the **receiving** barangay (`transfers.receive`), or
- CSWD (`transfers.manage`), on any pending request. CSWD's answer is
  recorded as `decided_as: "cswd"`.

Use `may_decide` on the row (4.5) to show or hide the buttons.

Accept has no body. Decline needs a reason:

```json
{ "reason": "Our hall is full too." }
```

The reason is required, 3 to 500 characters.

200 accept: `"message": "Accepted: the families are now at DA Building."`,
`data` = the updated row (`status: "accepted"`). The families' current
check-ins now point to the receiving center, with the check-in time kept.
Logged as `transfer_request.accepted`.

200 decline: `"message": "Declined. The sender chooses another center."`,
`data` = the updated row (`status: "declined"`, `decline_reason`). Nothing
moves. Logged as `transfer_request.declined`.

Either way, the official who sent the request is notified (4.6).

| Status | `message` (field) |
|---|---|
| 403 | `Only the receiving barangay or CSWD can answer this request.` (e.g. the sending official) |
| 422 | `Say why the request is declined.` (`reason`) |
| 422 | `This request was already answered.` (`status`): someone else answered first, or a repeat |
| 422 | the closed-event message (`status` or `evacuation_event_id`) |
| 404 | no request with that id |

### 4.5 `GET /transfer-requests`

Used for the Transfers screen. Query parameters (both optional):
- `status`: `pending`, `accepted` or `declined`
- `evacuation_event_id`: defaults to the user's selected event

CSWD sees every request; an official sees the requests to and from their
barangay. Pending requests come first, then newest first, up to 200.

```json
{ "success": true, "message": "Request successful.",
  "data": {
    "evacuation_event": { "id": 85, "name": "Typhoon Drill", "status": "active", "is_demo": false },
    "counts": { "pending": 2, "incoming_pending": 1 },
    "requests": [ {
      "id": 4, "status": "pending", "direction": "incoming",
      "from_center": { "id": 71, "name": "LWCES (B)" }, "to_center": { "id": 141, "name": "DA Building" },
      "from_barangay": { "id": 13, "name": "Binatagan" }, "to_barangay": { "id": 49, "name": "Tomolin" },
      "families": 1, "persons": 4,
      "family_list": [ { "head": "Bea Bituin", "members": 4 } ],
      "note": "Our hall is full.",
      "requested_by": { "id": 4, "name": "Binatagan Official" }, "requested_at": "2026-11-18T14:02:11+08:00",
      "decided_by": null, "decided_at": null, "decided_as": null, "decline_reason": null,
      "escalated_at": null, "waiting_minutes": 3, "may_decide": true } ] } }
```

| Field | Meaning |
|---|---|
| `counts.incoming_pending` | pending requests **to** the official's barangay; `null` for CSWD |
| `direction` | `incoming` (to my barangay), `outgoing` (sent by my barangay), `all` (CSWD) |
| `family_list` | **Privacy:** the receiving barangay gets only `head` and `members`. The sending barangay and CSWD also get `id` and `href` (`/families/{id}`). Never ask the server for more on the receiving side: `GET /families/{id}` stays 403 for them. |
| `persons` / `members` | people checked in when the request was made |
| `requested_by` | `null` for demo requests; show `from_barangay.name` instead |
| `decided_as` | `receiving_official` or `cswd` |
| `escalated_at` | when CSWD was told the request had waited 30 minutes; `null` before then |
| `waiting_minutes` | minutes since `requested_at` while pending; `null` once answered |
| `may_decide` | this user may accept or decline now |

All times are ISO 8601 with offset.

### 4.6 Notifications and the 30-minute escalation

Transfer notifications come through the existing `GET /notifications`
(`{ id, title, message, href, read, created_at }`; `href` is
`/transfer-requests`). Open the app's Transfers screen when one is tapped.
The list doesn't include the request id, so reload `GET
/transfer-requests`.

| To | `title` | `message` |
|---|---|---|
| receiving barangay's active officials | `Transfer request: 2 families (7 persons)` | `<name> asks to move 2 families (7 persons) from <center> to <center>. Note: …` |
| the requester, on accept | `Transfer accepted: 2 families to <center>` | `<name> accepted. The families are now at <center>.` (`<name> (CSWD)` when CSWD decided) |
| the requester, on decline | `Transfer declined: 2 families to <center>` | `<name> declined: <reason> Choose another center.` |
| every active CSWD account, once per request | `Transfer request waiting 30 minutes` | `<barangay> asked to move 2 families to <center>; no answer yet. CSWD can decide it.` |

Escalation: a request still pending 30 minutes after `requested_at` is
escalated once. `escalated_at` is set and CSWD is notified. The server
checks every minute (`transfers:escalate`), and again whenever anyone calls
`GET /transfer-requests` or `GET /notifications`. The apps don't trigger
it; they only show `escalated_at` and the notification. Nothing expires:
an escalated request can still be answered by the receiving barangay or
CSWD.

### 4.7 `GET /dashboard/city`: `actions.transfer_requests_pending`

CSWD's city dashboard `actions` now includes the number of pending
transfer requests in the selected event (0 when there is no event):

```json
"actions": { "centers_pending": 1, "center_edits_pending": 0, "count_submissions_pending": 3,
  "facility_requests_pending": 0, "transfer_requests_pending": 2, "shortfall_requests_pending": 0 }
```

When it is above 0, show "N transfer requests waiting", linking to the
Transfers screen with `status=pending`. Treat any `actions` key the app
doesn't recognise as optional.

## 5. Quick checklist

- [ ] Show `capacity_source` everywhere a capacity appears: "est." with a
      tooltip, and "– (capacity unknown)". Never show unknown as full.
- [ ] Officials: the dashboard `todo` item.
- [ ] Officials: "Move to another of our centers" (4.1) and "Ask another
      barangay's center" (4.2 → confirm → 4.3), with ticked families of
      one center together.
- [ ] Officials and CSWD: the Transfers screen (4.5) with Accept / Decline
      behind `may_decide` and a confirm dialog (4.4); the decline reason
      is required.
- [ ] CSWD: direct move with a reason (4.1); `transfer_requests_pending`
      on the dashboard (4.7); the 30-minute notification.
- [ ] Receiving side: show only `family_list[].head` and `.members`.
- [ ] All the writes in section 2 need a connection: disable them offline
      and check the list before retrying.
- [ ] Mobile resident map: "(estimated)" or "capacity unknown" from
      `/public/evacuation-centers` `capacity_source`.
