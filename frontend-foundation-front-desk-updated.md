# Front Desk Frontend Foundation

## Purpose
Front Desk is the operational workspace for **patient arrival and encounter check-in**. It must not duplicate Patients, Appointments, or Queues.

## Core workflow

**Appointment is optional. Not every patient has an appointment.**

### Scheduled patient

```text
Patient → Appointment → Arrival → Front Desk → Check-in → Encounter → Optional Queue → Service
```

### Walk-in patient

```text
Patient → Front Desk → Check-in → Encounter → Optional Queue → Service
```

Therefore:

```text
                    PATIENT
                   /       \
                  /         \
          APPOINTMENT      WALK-IN
              ↓               ↓
           ARRIVAL        FRONT DESK
              ↓               ↓
              └──────→ CHECK-IN
                          ↓
                       ENCOUNTER
                          ↓
                    OPTIONAL QUEUE
                          ↓
                       SERVICE
```

**Appointment is one possible entry path, not a required step before check-in.**

Mental model:

```text
Patients      = WHO?
Appointments  = WHEN?
Front Desk    = ARRIVED?
Queues        = WHERE?
Service       = WHAT IS BEING DONE?
```

## Ownership

Front Desk owns:
- Today's expected patients
- Arrival operations
- Arrived patients
- Walk-in workflow
- Encounter check-in
- Relevant appointment/patient context
- Optional queue placement during check-in when supported

Front Desk does **not** own:
- Patient registration/demographics
- Appointment booking/rescheduling/cancellation/confirmation/no-show
- Calendar management
- Queue calling, recall, hold/release, transfer, priority, or service lifecycle
- Clinical documentation/service completion

## Global navigation

The global menu is:

```text
Patients
Appointments
Front Desk
Queues
```

**Front Desk** must be a visible global menu item.

Clicking it opens the Front Desk workspace.

The backend currently exposes `/workspaces/reception`; the user-facing product label must remain **Front Desk**. If the existing frontend technical route is `/workspaces/reception`, it may be retained rather than inventing a backend route.

## Front Desk workspace

Use one workspace with internal operational views. The board backend is
merged (entry needs any of encounter/appointment/patient read; denied
sections compose empty with `restricted` flags) and returns more than the
three views below (metrics, queue options, referrals, week strip) — build
from the verified `Reception/Index` props, not from this sketch:

```text
FRONT DESK
────────────────────────────────
[Today]   [Arrived]   [Walk-ins]
────────────────────────────────
             Main content
```

### Today
Primary operational view. Show, where available:
- time
- patient
- appointment type
- resource/provider
- appointment status
- arrival status
- encounter status
- queue context
- next available action

### Arrived
Shows patients who have arrived and need or have completed check-in processing.

### Walk-ins
Entry point for patients without an existing appointment. Existing patients can proceed to check-in; new patients must use the canonical Patients registration workflow first.

## Required pages and entry points

| Page/workflow | Entry point | Global menu |
|---|---|---|
| Front Desk workspace | `/workspaces/reception` or established frontend equivalent | **Front Desk** |
| Today | Front Desk internal view | No |
| Arrived | Front Desk internal view | No |
| Walk-ins | Front Desk internal view | No |
| Check-in | From patient/appointment context | No |
| Patient registration | Patients workflow | Patients |
| Appointment details | Appointments workflow | Appointments |
| Queue details | Queues workflow | Queues |

Every user-facing page must have a deliberate navigation path. Do not create unreachable pages.

## Appointment relationship

Front Desk may display:
- appointment time
- patient
- appointment type
- resource
- appointment status
- arrival status

Appointment management remains owned by Appointments.

Do not duplicate booking, rescheduling, cancellation, confirmation, or no-show workflows.

## Record Arrival vs Check-in

These are different operations.

```text
Appointment
    ↓
Record Arrival
    ↓
Appointment marked ARRIVED

Front Desk
    ↓
Check In
    ↓
Encounter = ARRIVED
```

Use clear terminology:
- **Record arrival** = appointment operation
- **Check in** = encounter/front-desk operation

Do not collapse them into one generic action.

## Check-in

Verified backend capability:

```text
POST /workspaces/reception/check-in
```

It creates an `ARRIVED` encounter and can optionally:
- link appointment arrival;
- place the encounter into a queue.

Conceptual flow:

```text
Patient arrives
      ↓
Identify patient
      ↓
Review appointment or walk-in
      ↓
Check in
      ↓
Encounter = ARRIVED
      ↓
Optional queue placement
```

Backend is authoritative for creation, validation, permissions, appointment linkage, and queue placement.

## Check-in from Patient Chart

The Patient Chart may expose a contextual **Check in** action.

This does not make Patients the owner.

Both entry points must use the same canonical workflow:

```text
                 CheckInAction
                      │
             ┌────────┴────────┐
             ↓                 ↓
        Front Desk        Patient Chart
        primary entry    contextual entry
```

Use the same API, permissions, validation, and business rules.

Do not create separate `PatientChartCheckIn` and `ReceptionCheckIn` business implementations.

## Walk-ins

Conceptual flow:

```text
Walk-in
   ↓
Find patient
   ├── Existing → Check in
   └── New → Patients registration → Check in
```

Patient registration remains owned by Patients.

## Queue relationship

Check-in may optionally place a patient into a queue:

```text
Check in
   ↓
Encounter = ARRIVED
   ↓
Optional queue placement
   ↓
Queue = WAITING
   ↓
 Queue-specific assigned room only
```

Queue selection is a service destination, not just a label. Check-in shows the
queue's service capability and configured rooms; queues without an active room
assignment cannot be selected. The queue board offers only rooms assigned to
the ticket's queue, and the server rejects mismatched room calls. A called
ticket whose room no longer matches its queue cannot start service until it is
corrected or transferred.

This does not make Front Desk the owner of queue management.

Queues owns:
- call/recall
- start service
- hold/release
- skip/no-response
- transfer
- priority
- completion
- pause/resume/close

Front Desk may display queue context and link to Queues.

## Encounter lifecycle

Verified encounter operations include:

```text
ARRIVED → ACTIVE → COMPLETED
```

Starting an encounter uses the backend start operation and `encounter.update`.

Do not automatically start an encounter during check-in unless the product explicitly requires it.

Disposition/completion are encounter lifecycle operations and must not be treated as appointment actions.

## Front Desk transfer

The backend has `/reception/transfer`, which moves an encounter's active queue ticket and requires queue-transfer authorization.

Because this is a queue-ticket operation, canonical ownership remains **Queues**. If exposed from Front Desk, reuse the canonical Queue transfer workflow rather than creating a separate transfer implementation. (No queue-transfer UI exists in the frontend yet — Front Desk transfer stays out of scope until Queues lands.)

## Backend permissions

Check-in requires:

```text
encounter.encounters.create
```

When queue placement is requested, the backend also enforces:

```text
queue.ticket.place
```

The backend remains the final authorization authority. Do not infer permission merely from a user's role name.

## Backend route

Verified reception board:

```text
GET /workspaces/reception
```

Verified check-in:

```text
POST /workspaces/reception/check-in
```

Use only verified backend capabilities. Do not invent APIs to support frontend features.

## Unsupported functionality

Do not expose buttons for encounter operations that have no verified HTTP route, including unsupported cancel/abandon/admit/reopen operations.

Internal domain commands are not automatically frontend capabilities.

## Cross-module ownership

> **One canonical owner per business workflow; many contextual entry points are allowed.**

```text
Patients      → patient identity/chart
Appointments  → scheduling/appointment lifecycle
Front Desk    → arrival/encounter check-in
Queues        → live service flow
```

Context may cross modules, but canonical business logic must not be duplicated.

## Error and state handling

Handle:
- loading
- empty day
- no expected patients
- no arrived patients
- walk-in state
- check-in failure
- duplicate/stale check-in
- permission denial
- queue placement failure
- backend errors
- stale appointment/encounter state

Never claim check-in succeeded when the backend failed.

If check-in succeeds but optional queue placement fails, reflect the actual backend result accurately.

## Suggested frontend structure

```text
front-desk/
├── workspace/
│   ├── FrontDeskPage
│   ├── TodayView
│   ├── ArrivedView
│   └── WalkInsView
├── components/
│   ├── FrontDeskBoard
│   ├── PatientArrivalRow
│   ├── AppointmentContext
│   ├── EncounterStatus
│   ├── CheckInAction
│   └── QueueContext
├── actions/
│   └── CheckIn
└── api/
    └── reception
```

Follow existing project conventions for exact naming.

## Acceptance criteria

The implementation is correct when:
- Front Desk is a visible global menu item.
- Clicking it opens the Front Desk workspace.
- Today is the primary operational view.
- Arrived and Walk-ins have clear internal entry points.
- Check-in uses the verified backend capability.
- Check-in creates an ARRIVED encounter.
- Optional queue placement uses the canonical queue capability.
- Record arrival is clearly distinguished from check-in.
- Appointment context is visible without duplicating Appointments.
- Patient context is visible without duplicating Patients.
- Queue context is visible without duplicating Queues.
- Patient Chart can provide contextual check-in using the same workflow.
- Unsupported encounter operations are not exposed.
- Permissions are respected.
- Backend validation is authoritative.
- Loading, empty, error, and stale states are handled.
- Every user-facing page/view has an intentional entry point.
- No unreachable standalone page is created.

## Final mental model

```text
                         PATIENT
                            ↓
                      APPOINTMENT
                            ↓
                         ARRIVAL
                            ↓
                       FRONT DESK
                         CHECK-IN
                            ↓
                         ENCOUNTER
                         ARRIVED
                            ↓
                    Optional queue placement
                            ↓
                          QUEUE
                            ↓
                         SERVICE
```

**Front Desk is the operational workspace for arrival and check-in. It is not a replacement for Patients, Appointments, or Queues.**
