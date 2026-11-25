# API for the mobile and desktop apps (November 2026): center figures per event

Two events can be open at once (for example a practice "Typhoon Drill",
active, and "Incoming Typhoon", being monitored) and share a center. Every
figure for a center now follows ONE rule, on the web and in every endpoint
below (`App\Services\CenterOccupancy`). Paths are relative to `/api/v1`.

Nothing was removed or renamed. The existing fields keep their names; what
they count is stated below. The new fields are additions.

## 1. The rule

| What | Counts |
| --- | --- |
| `current_occupancy`, `occupancy_percent`, the progress bar, families | People inside for **the event** only. A closed event has nobody "now". |
| `effective_status` (`status` where the row says it is the status shown) | `active`: people inside for the event. `on_standby`: nobody inside for the event (`closed` instead when the barangay closed the center). `nearly_full` (75-89%) / `full` (90%+): **everyone inside**, see `total_occupancy`. |
| `remaining_persons`, `room_left`, `lacks_room`, "where to bring them", the "X is 92% full" alert, facility needs | **Everyone inside** (`total_occupancy`): a shared center is never over-filled. |

"The event" is the one the request names with `evacuation_event_id`, else
the user's selected event (`GET/PUT /selected-event`). **New:** naming
`evacuation_event_id` now also scopes the center figures of `GET
/evacuation-centers`, `GET /evacuation-centers/{id}`, `GET /dashboard/city`,
`GET /dashboard/barangay`, `GET /monitoring/{section}` and `GET
/volcano`, so every number in one answer is for the same event.

"Everyone inside" = people checked in inside the center for any open event
(active or monitoring) **of the same kind**: a practice event (`is_demo`)
only ever sees practice events, a real event only real events. Closed
events count nowhere.

The stored status officials set (`status` in `EvacuationCenterResource`,
`stored_status` in map rows) is unchanged and is what edit forms use.

## 2. New fields

On every staff center row: `GET /evacuation-centers` (plain list and
`?detail=1`), `GET /evacuation-centers/{id}`, `GET /gis/map-data`, `GET
/gis/centers/{id}`, `GET /gis/barangays/{id}` `centers[]`, `GET
/dashboard/barangay` `centers[]`, `GET /gis/barangays/{id}/destinations`,
`GET /monitoring/centers` `data.centers[]`, `GET /volcano` centers, transfer
requests `from_center` / `to_center`.

```json
{
  "current_occupancy": 0,
  "occupancy_percent": 0.0,
  "total_occupancy": 87,
  "other_events": [
    { "event_id": 48, "name": "Typhoon Drill", "status": "active", "persons": 87, "families": 21 }
  ],
  "occupancy_note": "87 people here for Typhoon Drill"
}
```

| Field | Notes |
| --- | --- |
| `total_occupancy` | Everyone inside now (this event + `other_events`). |
| `other_events` | The other open events of the same kind with people inside, most people first. `[]` when none. |
| `occupancy_note` | Ready to show under the center. `null` when `other_events` is empty. When everyone together makes it nearly full or full: `"Full: 87 here for Typhoon Drill + 900 for this event"` (`"Nearly full: …"`); the `+ N for this event` part is left out when the event has nobody there. |

The plain `GET /evacuation-centers` list also gains `capacity_persons`,
`current_occupancy` and `occupancy_percent`, after its old keys.

Public endpoints (`/public/...`, no login) are unchanged: they count every
open event (a practice event only with `ELIKAS_PUBLIC_SHOW_DEMO`) and have
no `other_events`.

## 3. Example

Typhoon Drill (#48, active) has 87 people in LWCES (B), capacity 1,088.
Incoming Typhoon (#49, monitoring) has nobody there.

| | #48 selected | #49 selected |
| --- | --- | --- |
| `current_occupancy` | 87 | 0 |
| `occupancy_percent` | 8.0 | 0.0 |
| `effective_status` | `active` | `on_standby` |
| `total_occupancy` | 87 | 87 |
| `occupancy_note` | null | "87 people here for Typhoon Drill" |
| `remaining_persons` | 1,001 | 1,001 |

## 4. What the released apps show without an update

The server cannot fix every screen of the released apps, because some of
their numbers do not come from a staff endpoint for the selected event.

### Desktop 2.1.1

- **Fixed by the server, after "Refresh reference data":** the cached
  `current_occupancy` and `effective_status` of each center are for the
  event selected at that refresh, so the board and "Your centers" show the
  event's own count.
- **Still wrong until an update:**
  1. Switching events in the top bar does not reload the centers
     (`SelectedEventController` → `SelectedEvent::choose()` only sends `PUT
     /selected-event`). The count shown is the one of the event selected at
     the last refresh. Reload `GET evacuation-centers?detail=1` (with
     `evacuation_event_id`) after a switch.
  2. `EvacuationCenter::displayStatus()` works the status out itself: with
     nobody inside it shows the stored `status` (so a center an official set
     to "active" shows Active with 0 people); Full comes from
     `occupancyHere()` (this event's count, not everyone inside). Show the
     server's `effective_status`, and only switch an `on_standby` center to
     `active` locally when unsent arrivals for the selected event exist.
  3. `occupancyHere()`, `needsSpace()` and the "is at 92% of its capacity"
     banner add unsent arrivals of **any** open event. Count only the
     selected event's for the number shown; use `total_occupancy` + unsent
     arrivals for Full and needsSpace.
  4. Show `occupancy_note` under the center (cache it with the center).

### Mobile 0.4.0

- **Fixed by the server:** the status chip on the Centers list and the EC
  Board list (`effective_status` of the staff `GET /evacuation-centers`).
- **Still wrong until an update:**
  1. The Centers list (`StaffEvacuationCentersListPage`), the EC Board list
     and its "Evacuees" total (`EcBoardCentersListPage`, sum of
     `public.currentOccupancy`) take the number, the bar and its colour from
     the **public** `GET /public/evacuation-centers`, which has no login and
     no event, so it counts every open event (87 under #49). Read
     `current_occupancy`, `occupancy_percent`, `capacity_persons`,
     `total_occupancy` and `occupancy_note` from the staff `GET
     /evacuation-centers` instead (now in the plain list), with
     `evacuation_event_id` of the current event; keep the public copy for
     the map location and photo only.
  2. The center detail page (`StaffEvacuationCenterDetailPage`) shows the
     stored `status` (`EvacuationCenterModel.fromStaffJson` reads
     `json['status']`). Read `effective_status`.
  3. Show `occupancy_note`.

## 5. Correcting a sent registration (added November 26, 2026)

For the apps' Sync > Sent "Edit". Both fields are optional additions;
requests without them work as before.

| Endpoint | New field | Rules |
| --- | --- | --- |
| `PATCH /evacuees/{id}` | `age_bracket_override` | One of the seven age groups (`EvacuationCenterQuickCount::AGE_BRACKETS`: `infant`, `toddler`, `preschooler`, `school_age`, `teenage`, `adult`, `senior_citizen`). 422 otherwise. A birthdate, once known, still decides `age_bracket`. When the person is the family's head and has no birthdate, the family's "head is a minor" follows the new age group, as at registration. |
| `PATCH /families/{id}/household` | `name` | The household's name as registered. Left out: kept. Trimmed; never blank; max 150. The change is written in the activity log ("name changed from … to …"). |

Same permissions and barangay rules as before (`evacuees.manage`, own
barangay for officials).
