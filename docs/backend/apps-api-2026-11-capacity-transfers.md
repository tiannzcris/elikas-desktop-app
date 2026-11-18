# Center capacity and transfers between barangays: API changes for the desktop and Flutter apps (18 November 2026)

Two parts: center capacity (section 1) and moving evacuees when a center is
full (section 2). The checklist is at the end.

Follows docs/apps-api-2026-11-event-switcher-late-entries.md. All
endpoints under `/api/v1`, same envelope and permissions as there.

Full reference (request and response JSON, permissions per role, every
error message, online-only calls): docs/apps-api-2026-11-transfers-capacity.md.

## 1. Center capacity

Method and limits: docs/center-capacity.md.

- Every center carries `capacity_source`: `official` (entered or imported),
  `estimated` (filled once from the 2025 peak or the median of its type),
  or `unknown`; staff responses also `capacity_basis` (`peak_2025`,
  `type_median`, null). Places: `EvacuationCenterResource` (all
  `/evacuation-centers` endpoints), `/gis/map-data`, `/gis/barangays/{id}`
  (centers), `/gis/centers/{id}`, `/gis/barangays/{id}/destinations`,
  `/public/evacuation-centers`, `/monitoring/centers` rows.
- Show an estimate as "150 est." with a tooltip "Estimated from the 2025
  peak" (or "Estimated: median of the same type of center"); an unknown
  capacity as "48 / – (capacity unknown)". A center with an unknown
  capacity is never full (unchanged rule: full at 90% of a capacity).
- When an official enters or edits a capacity (for an approved center, once
  CSWD approves the edit), it becomes `official`.
- `GET /dashboard/barangay` adds `todo`: `[{ kind: "center_capacity", text:
  "3 of your 4 centers have no capacity or only an estimate", detail, href:
  "/evacuation-centers?filter=attention", counts }]` (empty when none).
- `GET /dashboard/city`, `GET /monitoring/readiness`, `GET
  /monitoring/centers` add `capacity: { official, estimated, unknown,
  total }`.
- `POST /evacuation-centers/capacity-import` (CSWD, `centers.import_capacity`):
  `file` (CSV: `center,barangay,capacity_families,capacity_persons`) or
  `csv` (its text), `dry_run` (default true). 200 `{ dry_run, rows, valid,
  changes, errors: ["Row 4: No center named …"], preview: [{ row, given,
  center, current, new, changes, errors }] }`. With `dry_run=false`: all or
  nothing -- 422 with that body under `errors` while any row has an error.
  Imported capacities are official; logged
  (`evacuation_center.capacity_imported`).

## 2. Moving evacuees when a center is full

Code: `App\Services\CenterTransfers`, `TransferRequestController`. Web page:
/transfer-requests ("Transfers").

### Same barangay: a direct move

`PATCH /families/{id}/evacuation-center` (unchanged, permission
`evacuees.transfer`): a barangay official moves their own families between
their own barangay's centers, no approval. CSWD moves a family to any
center with a `reason` (required).

### Another barangay's center: a request

New permissions: `transfers.request` and `transfers.receive` (barangay
officials), `transfers.manage` (CSWD).

`GET /transfer-requests/suggestions?from_center_id=12&persons=7`
(`transfers.request`): approved, open centers of OTHER barangays with
space (capacity minus people inside), nearest first; centers with an
unknown capacity last (`capacity_unknown: true`); full ones left out.
Each row: `evacuation_center {id, name, type, status}`, `barangay`,
`distance_km`, `distance_basis` (`center` or `barangay_centroid`),
`capacity_persons`, `capacity_source`, `remaining_persons`, `fits` (room
for all the persons asked), `capacity_unknown`.

`POST /transfer-requests` (`transfers.request`):

```json
{ "evacuation_event_id": 85, "family_ids": [5669, 5672], "to_center_id": 141, "note": "Our hall is full." }
```

- The families must be the official's own barangay's, in that event, with
  someone checked in, all at one center; the target must be another
  barangay's approved, open center; a family already in a waiting request
  is refused (422 messages say which). CSWD gets 403 here (it moves
  directly instead).
- 201: the request (see the row below). The receiving barangay's active
  officials get an in-app notification ("Transfer request: 2 families (7
  persons)", `href` /transfer-requests; `GET /notifications` does not
  return the request id). Until it is accepted the families stay counted
  at their center.

`POST /transfer-requests/{id}/accept` and `POST /transfer-requests/{id}/decline`
(body `{ "reason": "…" }`, required, 422 "Say why the request is declined.")
-- an official of the receiving barangay, or CSWD (any pending request;
`decided_as: "cswd"`). Accept moves the families' open stays at their
center to the target center: check-in time kept, the receiving center's
EC Board cumulative counts updated, logged
(`transfer_request.accepted`). Decline leaves everything as it was; the
sender chooses another center. The requester is notified either way.
Answering twice, or an event that has closed: 422; anyone else: 403.

`GET /transfer-requests?status=pending|accepted|declined&evacuation_event_id=`
(default: the selected event): CSWD all; officials those to and from their
barangay. A request unanswered for 30 minutes is escalated: CSWD is
notified once ("Transfer request waiting 30 minutes"). The scheduler runs
`transfers:escalate` every minute; this list and `GET /notifications` run
the same check.

Row:

```json
{ "id": 4, "status": "pending", "direction": "incoming",
  "from_center": {"id": 71, "name": "LWCES (B)"}, "to_center": {"id": 141, "name": "DA Building"},
  "from_barangay": {"id": 13, "name": "Binatagan"}, "to_barangay": {"id": 49, "name": "Tomolin"},
  "families": 1, "persons": 4,
  "family_list": [ { "head": "Bea Demo-Bituin", "members": 4 } ],
  "note": null, "requested_by": {"id": 4, "name": "…"}, "requested_at": "…",
  "decided_by": null, "decided_at": null, "decided_as": null, "decline_reason": null,
  "escalated_at": null, "waiting_minutes": 3, "may_decide": true }
```

Privacy: the receiving barangay sees only each family's head and number of
members (`family_list` without `id`/`href`); the home barangay and CSWD also
get `id` and `href`. Full details stay with the home barangay
(`GET /families/{id}` stays 403 for the receiver).

Counts: families keep their home barangay for every count, final count and
DROMIC; a center's occupancy counts whoever stays there (transferred
families included). `GET /dashboard/city` `actions.transfer_requests_pending`.

Confirm before sending, accepting, declining and any CSWD decision.

## What the apps must change

### Desktop app

1. Show `capacity_source`: "est." with its tooltip, "– (capacity unknown)"
   instead of a blank or 0, on every center card, list and map popup.
2. Officials: show the dashboard `todo` items (link to the centers needing
   a capacity).
3. CSWD (optional): the capacity counts on the dashboard and Monitoring;
   the CSV import stays on the web.
4. Officials, a full center: "Move to another of our centers" (the existing
   PATCH) and "Ask another barangay's center": the suggestions, a note, a
   confirm dialog, `POST /transfer-requests`; ticked families of one center
   together.
5. Officials: a Transfers screen -- requests to their barangay with Accept /
   Decline (reason) after a confirm dialog, and the ones they sent with
   their answer; react to the transfer notifications (`href`
   /transfer-requests).
6. CSWD: the same screen with every request (pending, accepted, declined,
   times) and Accept / Decline on any pending one; the "waiting 30 minutes"
   notification.
7. Never show the receiving barangay more than `family_list[].head` and
   `.members`.

### Flutter app

- Resident map and center details: show an estimated capacity as
  "(estimated)" and an unknown one as "capacity unknown"
  (`/public/evacuation-centers` `capacity_source`).
- Staff features, if the build has them: as the desktop app.
