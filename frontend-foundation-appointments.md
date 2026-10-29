# Appointments Frontend Foundation

## 1. Purpose

This document defines the frontend architecture and implementation boundaries for the **Appointments** workspace, based on the currently verified backend Scheduling capabilities.

The goal is to prevent duplicated workflows, invented backend capabilities, overlap with Patients/Front Desk/Queues, and unsupported frontend routes.

---

## 2. Core Architecture

The patient-operations workflow is:

```text
Patient → Appointment → Arrival → Check-in → Queue → Service
```

Mental model:

```text
Patients      = WHO?
Appointments  = WHEN?
Front Desk    = ARRIVED?
Queues        = WHERE?
Service       = WHAT IS BEING DONE?
```

Appointments owns the **WHEN** part of the workflow.

---

## 3. Appointments Ownership

Appointments owns:

- Calendar
- Date-range calendar counts
- Booking appointments
- Rescheduling
- Cancellation
- Confirmation
- Recording appointment arrival
- No-show
- Appointment details/context
- Availability
- Resource/date/type availability
- Slot capacity and overlap validation
- Appointment type/resource selection

Appointments does **not** own:

- Patient registration
- Patient demographic editing
- Patient chart ownership
- Encounter check-in
- Queue placement
- Calling patients
- Queue transfers
- Starting/completing service
- Clinical documentation
- Schedule administration without an HTTP contract
- Slot administration without an HTTP contract
- Waitlist administration without an HTTP contract

---

## 4. Canonical Appointments Workspace

The verified backend supports:

```text
/workspaces/appointments/calendar
```

as the actual Appointments Calendar workspace.

The backend currently has:

```text
/workspaces/appointments
        ↓
    Reception
```

Therefore:

> Do not create a separate `/workspaces/appointments` appointment-list workspace unless the backend contract changes and explicitly supports it.

The current canonical Appointments workspace is:

```text
/workspaces/appointments/calendar
```

---

## 5. Pages, Routes, and Navigation

Every user-facing page must have:

1. a defined purpose;
2. a defined route;
3. a defined navigation entry point;
4. a defined owner;
5. clear rules for how users reach it.

A page must not be created and left unreachable.

### Global navigation

The primary application menu is:

```text
Patients
Appointments
Front Desk
Queues
```

Appointments must have a visible global menu link:

```text
Appointments
    ↓
/workspaces/appointments/calendar
```

The Calendar is the canonical Appointments workspace currently supported by the backend.

### Required Appointments pages and workflows

| Page / workflow | Route / entry point | Global menu | Purpose |
|---|---|---|---|
| Appointments Calendar | `/workspaces/appointments/calendar` | **Appointments** | Primary appointment workspace |
| Appointment Details | From Calendar appointment | No separate menu item | View appointment and patient/scheduling context |
| Book Appointment | From Calendar and contextual Patient Chart action | No separate menu item | Create an appointment |
| Reschedule Appointment | From appointment actions | No separate menu item | Change appointment date/time/resource |
| Cancel Appointment | From appointment actions | No separate menu item | Cancel an appointment |
| Confirm Appointment | From appointment actions | No separate menu item | Confirm an appointment |
| Record Arrival | From appointment actions / appropriate Front Desk context | No separate menu item | Record appointment arrival |
| No-show | From appointment actions | No separate menu item | Mark appointment as no-show |

These workflows may be implemented as pages, dialogs, drawers, or other appropriate UI surfaces depending on the existing frontend conventions. They do not automatically require separate global routes.

### Appointment Calendar navigation

The intended navigation is:

```text
Global Menu
    │
    └── Appointments
            │
            ↓
    Appointments Calendar
    /workspaces/appointments/calendar
            │
            ├── Open appointment
            ├── Book appointment
            ├── Reschedule
            ├── Cancel
            ├── Confirm
            ├── Record arrival
            └── No-show
```

### Contextual navigation

The Patient Chart may provide contextual entry points:

```text
Patient Chart
    │
    ├── Book Appointment
    ├── View Appointment
    ├── Reschedule
    └── Cancel
```

These must invoke the same canonical Appointments workflows.

Front Desk may provide appointment context and appropriate arrival actions, but this does not make Front Desk the owner of appointment management.

### Unsupported appointment-list route

Do **not** create a separate:

```text
/workspaces/appointments
```

appointment-list page.

The currently verified backend behavior is:

```text
/workspaces/appointments
        ↓
    redirects to
      Reception
```

No separate appointment-list page route was verified.

If the product later requires an Appointments list page, that must be a deliberate architecture change backed by an appropriate backend/frontend route contract.

### Navigation acceptance rule

> **Every user-facing page must be reachable through an intentional navigation path.**

For the Appointments implementation, this means:

- the Calendar has a visible **Appointments** global menu link;
- appointment details are reachable from Calendar appointments;
- booking is reachable from Calendar and permitted contextual entry points;
- appointment actions are reachable from the appropriate appointment context;
- contextual Patient Chart actions reuse canonical Appointments workflows;
- no unsupported global Appointments list route is invented;
- no page is implemented without defining how users reach it.

---

## 7. Information Architecture

The current frontend should be centered on the Calendar.

```text
Appointments
└── Calendar
    ├── Day
    ├── Week
    └── Month
```

The Calendar can expose:

- appointments;
- appointment counts;
- availability;
- appointment details;
- appointment actions.

A separate global appointment-list page is **not part of the current foundation** because no corresponding backend route was verified.

---

## 7. Calendar

The Calendar is the primary Appointments workspace.

It should support:

- date navigation;
- date-range calendar data;
- calendar counts;
- appointment display;
- appointment status;
- patient context;
- appointment type;
- resource context;
- opening appointment details;
- supported appointment actions.

Recommended views:

```text
Day
Week
Month
```

Do not invent appointment data that the backend does not provide.

---

## 8. Appointment Context

An appointment should provide, where supported:

```text
Patient
Appointment type
Date
Start/end time
Resource
Status
Schedule context
Reason
Slot
Arrival state
```

The Patient identity should link to the Patient Chart.

Appointments must not duplicate the Patient Chart.

---

## 9. Appointment Lifecycle

Use the backend's real appointment statuses and transitions. Do not create frontend-only lifecycle states.

Conceptually:

```text
                    BOOKED
                       │
              ┌────────┼────────┐
              ↓        ↓        ↓
         CONFIRMED   CANCELLED  NO_SHOW
              │
              ↓
           ARRIVED
              │
              ↓
        FRONT DESK
              │
              ↓
          CHECK-IN
              │
              ↓
           QUEUE
              │
              ↓
           SERVICE
```

### Record Arrival vs Check-in

These are different operations.

**Record arrival** changes appointment arrival state.

**Check-in** belongs to the Front Desk / Encounter workflow.

Therefore:

```text
Appointment → Record Arrival
Front Desk  → Check In
```

Do not implement them as one generic action.

---

## 10. Supported Appointment Actions

### Book

Book for a patient using:

- appointment type;
- resource;
- date/time;
- optional slot;
- optional reason.

### Reschedule

Use the canonical scheduling reschedule workflow. Do not manually mutate dates in the frontend.

### Cancel

Use the canonical scheduling cancellation workflow.

### Confirm

Use the canonical scheduling confirmation workflow.

### Record Arrival

Record that the patient has arrived for the appointment.

This is appointment state, not encounter check-in.

### No-show

Mark an appointment as no-show through the scheduling workflow.

---

## 11. Booking Workflow

Recommended flow:

```text
Select Patient
     ↓
Select Appointment Type
     ↓
Select Resource
     ↓
Select Date
     ↓
Check Availability
     ↓
Select Time / Slot
     ↓
Optional Reason
     ↓
Review
     ↓
Book
```

The backend is authoritative for availability and booking validation.

The frontend must handle a slot becoming unavailable between lookup and booking:

```text
Slot appears available
        ↓
Another booking occurs
        ↓
User submits
        ↓
Backend rejects overlap/capacity
        ↓
Refresh availability
```

---

## 12. Availability

Backend-supported availability includes:

- resource;
- date;
- appointment type;
- slots;
- capacity;
- overlapping-booking checks.

Do not implement a second authoritative availability engine in React.

Frontend responsibility:

```text
Request availability
        ↓
Display available times
        ↓
Allow selection
        ↓
Submit booking
        ↓
Handle backend validation
```

---

## 13. Appointment Types and Resources

Booking requires appointment type and resource selection.

Use backend-provided values.

Do not hardcode appointment types or resources.

Do not create administration screens for them unless HTTP APIs are later added and verified.

---

## 14. Schedule, Slot, and Waitlist Boundaries

Scheduling contains internal functionality for:

- schedules;
- slots;
- appointment types/resources;
- waitlists.

The verified route layer does not expose these as general frontend administration APIs.

Therefore:

> Do not invent frontend administration pages for schedules, slots, appointment types/resources, or waitlists.

Internal backend commands are not automatically frontend capabilities.

---

## 15. Patient Chart Contextual Actions

The Patient Chart may expose appointment actions:

```text
Patient Chart
────────────────────────

Appointments

09:30
General Consultation
Dr. Example
CONFIRMED

[View] [Reschedule] [Cancel]
```

It may also expose:

```text
[Book Appointment]
```

These actions remain owned by **Appointments**.

The Patient Chart is only a contextual entry point.

### Core rule

> Module ownership determines where the canonical workflow lives. It does not determine every place from which that workflow may be initiated.

Therefore:

```text
Patient Chart
     │
     ├── Book Appointment
     ├── Reschedule
     ├── Cancel
     └── View Appointment
             │
             ↓
      SAME APPOINTMENTS
        WORKFLOW
```

Do not create separate patient-chart implementations of appointment business logic.

Reuse the canonical Appointments actions/components/workflows.

---

## 16. Front Desk Relationship

Front Desk needs appointment information because it operates the arrival workflow.

Example:

```text
Front Desk

Today's expected patients

09:00  John Smith
       General Consultation
       CONFIRMED
       [Record arrival]

09:30  Jane Doe
       Follow-up
       ARRIVED
       [Check in]
```

This does not make Front Desk the owner of appointment management.

Front Desk may display:

- appointment time;
- patient;
- appointment type;
- resource;
- appointment status;
- arrival status.

Appointment lifecycle actions remain owned by Appointments.

---

## 17. Queues Relationship

Appointments must not manage queue state.

Queue operations belong to Queues:

- call;
- recall;
- start service;
- hold;
- release;
- skip;
- no-response;
- transfer;
- priority;
- complete.

Appointments may display queue context when useful, but should link to Queues rather than become a second queue board.

---

## 18. Cross-Module Ownership Rule

> **One canonical owner per business workflow; many contextual entry points are allowed.**

Examples:

| Workflow | Canonical owner | Contextual entry |
|---|---|---|
| Patient registration | Patients | — |
| Book appointment | Appointments | Patient Chart |
| Reschedule | Appointments | Patient Chart |
| Cancel appointment | Appointments | Patient Chart |
| Record arrival | Appointments | Front Desk |
| Check-in | Front Desk | Patient Chart/contextual |
| Queue placement | Queues | Check-in flow |
| Call patient | Queues | Queue workspace |
| Queue transfer | Queues | Queue workspace |

Contextual entry points must reuse the canonical workflow.

---

## 19. Navigation

Primary global navigation:

```text
Patients
Appointments
Front Desk
Queues
```

Appointments:

```text
Appointments
    └── Calendar
```

Do not add a redundant Reception destination inside Appointments.

Do not treat Front Desk as the Appointments workspace.

Do not create a second appointment calendar inside Front Desk.

---

## 20. Error and State Handling

The Appointments UI must handle:

- loading;
- empty calendar;
- no appointments for a date/range;
- backend errors;
- unavailable slots;
- overlap rejection;
- capacity rejection;
- cancelled appointments;
- no-show appointments;
- stale data;
- permission restrictions.

Never pretend a mutation succeeded when the backend failed.

After successful mutations, refresh or reconcile the canonical appointment/calendar data.

---

## 21. Permissions

Appointment actions must respect backend permissions.

Frontend authorization should:

1. use the application's existing authorization mechanism;
2. hide or disable actions appropriately;
3. still rely on backend authorization as final authority;
4. handle authorization failures gracefully.

Never bypass backend permission checks.

---

## 22. Routing Constraints

Current verified routing:

```text
/workspaces/appointments
        ↓
      redirect
        ↓
Reception
```

and:

```text
/workspaces/appointments/calendar
        ↓
Appointments Calendar
```

Therefore the frontend must not assume `/workspaces/appointments` is an existing Appointments landing/list page.

If a future Appointments landing route is needed, that is a deliberate architecture change and must be verified against the backend contract.

---

## 23. Suggested Frontend Structure

```text
resources/js/
├── pages/
│   └── Appointments/
│       └── Calendar.tsx          # Inertia page and AppLayout composition
└── features/
        └── appointments/
                ├── components/
                │   ├── BookAppointmentPanel.tsx
                │   └── CalendarGrid.tsx
                └── types/
                        └── calendar.ts       # Calendar page props from the backend
```

Follow the existing Patients structure: route-level pages live under `pages/` and own the page shell, breadcrumbs, and header. Reusable Appointments UI belongs under `features/appointments/components/`. Add further folders only when the corresponding feature code is introduced. Names should follow existing project conventions, and action logic must remain canonical and reusable.

---

## 24. Avoid Duplicate Action Implementations

Bad:

```text
Appointments
└── AppointmentRescheduleForm

Patient Chart
└── PatientAppointmentRescheduleForm

Front Desk
└── ReceptionRescheduleForm
```

Good:

```text
                   RescheduleAppointment
                           │
          ┌────────────────┼────────────────┐
          ↓                ↓                ↓
    Appointments       Patient Chart     Front Desk
    entry point        contextual        contextual
                       entry point       entry point
```

The same applies to booking, cancellation, confirmation, and arrival.

---

## 25. Backend Alignment Rules

The frontend must:

1. Not invent endpoints.
2. Not invent appointment statuses.
3. Not invent lifecycle transitions.
4. Not implement client-side authoritative availability.
5. Not create unsupported schedule/slot/waitlist administration.
6. Not create an unsupported appointment-list route.
7. Not duplicate appointment actions in Patient Chart or Front Desk.
8. Reuse canonical appointment workflows from contextual entry points.
9. Respect backend permissions.
10. Treat backend validation as authoritative.

---

## 26. Acceptance Criteria

The Appointments implementation is architecturally correct when:

- `/workspaces/appointments/calendar` is the canonical Appointments workspace;
- Calendar views use verified backend data;
- date-range counts are supported where appropriate;
- booking supports patient, type, resource, date/time, optional slot, and reason;
- availability comes from the backend;
- capacity and overlap validation remain backend-authoritative;
- reschedule uses the canonical scheduling workflow;
- cancel uses the canonical scheduling workflow;
- confirm uses the canonical scheduling workflow;
- record arrival is clearly distinguished from encounter check-in;
- no-show uses the canonical scheduling workflow;
- Patient Chart can expose appointment actions without duplicating them;
- Front Desk can display appointment context without becoming the appointment owner;
- Queues remain responsible for queue operations;
- no unsupported appointment-list route is invented;
- no unsupported schedule/slot/waitlist administration is invented;
- permissions are respected;
- loading, empty, error, and stale-state conditions are handled;
- backend failures are surfaced correctly;
- no frontend-only appointment lifecycle is introduced.

---

## 27. Final Mental Model

```text
                         PATIENT
                            │
                            ↓
                      APPOINTMENT
                            │
             ┌──────────────┼──────────────┐
             ↓              ↓              ↓
           BOOK         RESCHEDULE       CANCEL
             │
             ↓
         CONFIRMED
             │
             ↓
          ARRIVAL
             │
             ↓
        FRONT DESK
             │
          CHECK-IN
             │
             ↓
           QUEUE
             │
             ↓
          SERVICE
```

Ownership:

```text
PATIENTS      → patient identity and chart
APPOINTMENTS  → scheduling and appointment lifecycle
FRONT DESK    → arrival and encounter check-in
QUEUES        → live service flow
```

The key architectural rule is:

> **One canonical owner per workflow, with contextual entry points allowed from other modules.**

Appointments owns the appointment lifecycle without requiring every appointment action to originate only from the Appointments Calendar.
