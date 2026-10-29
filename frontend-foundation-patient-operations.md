# Frontend Foundation --- Patient Operations

## Purpose

This document defines the frontend information architecture for the
patient operational workflow across:

-   **Patients**
-   **Appointments**
-   **Front Desk**
-   **Queues**

The goal is to prevent duplicated functionality, unclear ownership, and
workflows being spread across multiple modules.

The backend audit has already established that these domains are
supported by separate backend responsibilities. The frontend must
represent those boundaries clearly.

------------------------------------------------------------------------

# 1. Core Architecture

The system should be understood as:

``` text
Patients
   │
   ├── Appointments
   │       "When is the patient expected?"
   │
   ├── Front Desk
   │       "Has the patient arrived and been checked in?"
   │
   └── Queues
           "Where is the patient waiting or being served?"
```

The operational journey is:

``` text
Appointment → Arrival → Check-in → Queue → Service
```

A patient may enter this flow through either:

``` text
Appointment → Arrival
```

or:

``` text
Walk-in → Arrival
```

Patients is not another step in this workflow. It is the underlying
patient identity and record that all operational modules reference.

------------------------------------------------------------------------

# 2. Module Ownership

## Patients

### Purpose

Patients answers:

> **Who is this patient?**

Patients owns:

-   Patient registration
-   Patient search
-   Patient identity
-   Demographics
-   Patient information
-   Patient chart
-   Patient-specific history and records

The registration form autosaves an encrypted, incomplete draft scoped to the
signed-in user and active facility. A draft is not a patient record; patient
identity is created only when registration is submitted successfully. Drafts
expire after seven days and are purged daily. Uploaded photos are not included
in drafts and must be selected again after the form is reopened.

The chart's sticky note is a **patient-level operational reminder**, not
clinical documentation. It is tenant-wide, has one active entry per patient,
and can be edited or archived by users with patient update permission. Archived
content is retained for auditability; the note is not tied to an encounter.

Patients does **not** own:

- Appointment scheduling
- Check-in
- Queue management
- Calling patients
- Queue transfers

However, **not owning a workflow does not mean the workflow can only be initiated from its owning module.**

The Patient Chart may expose **contextual actions** from other modules when those actions are useful in the patient context.

For example:

```text
Patient Chart
John Smith
────────────────────────
Appointment: 09:30
Status: ARRIVED

[Check in]
```

The **Check in** action is still owned by **Front Desk**. The Patient Chart is only providing a contextual entry point to the same check-in workflow.

### Contextual action rule

A contextual action from another module must:

- use the same canonical workflow as the owning module;
- use the same backend endpoint/contract;
- use the same permission checks;
- use the same validation and business rules;
- not create a second implementation of the workflow;
- return the user to an appropriate contextual state after completion.

Therefore:

```text
                    CHECK-IN WORKFLOW
                           │
              ┌────────────┴────────────┐
              │                         │
         Front Desk                Patient Chart
       canonical entry           contextual entry
              │                         │
              └────────────┬────────────┘
                           ▼
                  SAME CHECK-IN ACTION
                  SAME API / RULES
```

Do **not** build separate implementations such as:

```text
Patient Chart → its own CheckInForm
Front Desk    → another CheckInForm
```

Instead, reuse the same action/component/workflow:

```text
CheckInAction / CheckInDialog
        ↑
        ├── Front Desk
        └── Patient Chart
```

The same principle applies to other workflows.

Examples:

| Contextual action | Owning module |
|---|---|
| Check in patient | Front Desk |
| Book appointment | Appointments |
| Reschedule appointment | Appointments |
| Cancel appointment | Appointments |
| View queue status | Queues |
| Queue transfer | Queues |
| Call patient | Queues |

Whether an action should be exposed from the Patient Chart depends on whether it is appropriate to that patient context and the user's permissions.

### Core distinction

> **Ownership determines where the canonical workflow lives. It does not determine every place from which that workflow may be initiated.**

The Patient Chart is therefore allowed to provide contextual entry points into other modules without becoming the owner of those workflows.

The existing Patients frontend is considered established and is not part of this architecture redesign.

------------------------------------------------------------------------

# 3. Appointments

## Purpose

Appointments answers:

> **When is the patient expected?**

Appointments owns the complete appointment lifecycle.

### Owned actions

-   Book appointment
-   Reschedule appointment
-   Cancel appointment
-   Confirm appointment
-   Record no-show
-   View appointment calendar
-   View appointment list
-   View appointment availability

### Appointments may display

-   Patient identity
-   Appointment status
-   Arrival status
-   Related visit/encounter information where supported
-   Relevant queue/service context where useful

### Appointments must not own

-   Patient check-in
-   Queue calling
-   Queue transfer
-   Queue service actions

### Principle

An appointment can become:

``` text
BOOKED
→ ARRIVED
→ service-related states
```

But the appointment module should not become the place where the patient
is operationally moved through queues.

------------------------------------------------------------------------

# 4. Front Desk

## Purpose

Front Desk answers:

> **Who has arrived today, and who needs to be checked in?**

Front Desk is the daily arrival and check-in workspace.

### Front Desk should present

-   Today's expected patients
-   Today's appointments relevant to arrival
-   Arrived patients
-   Walk-ins
-   Patients awaiting check-in
-   Checked-in patients
-   Basic appointment/visit status
-   Relevant current waiting status

### Owned action

The primary operational action is:

**Check in patient**

The backend supports this through the reception/check-in workflow.

### Front Desk must not own

-   Appointment booking
-   Appointment rescheduling
-   Appointment cancellation
-   Appointment confirmation
-   Queue calling
-   Queue recall
-   Queue transfer
-   Queue hold/release
-   Queue service completion

### Important distinction

Front Desk may **display appointment information**, but it does not own
appointments.

Front Desk may **display queue information**, but it does not own
queues.

This is intentional.

------------------------------------------------------------------------

# 5. Queues

## Purpose

Queues answers:

> **Where is the patient now, and what happens next?**

Queues is the live service-flow workspace.

### Queues owns

-   Viewing queue boards
-   Placing patients into queues
-   Calling patients
-   Recalling patients
-   Starting service
-   Completing service
-   Holding patients
-   Releasing patients
-   Skipping patients
-   Recording no-response
-   Recording leave
-   Transferring patients between queues
-   Managing queue priority
-   Queue operational state

### Queue lifecycle

The queue workflow is approximately:

``` text
WAITING
   ↓
CALLED
   ↓
IN_SERVICE
   ↓
COMPLETED
```

Other valid paths include:

``` text
WAITING → HOLD → WAITING
CALLED → HOLD → WAITING
WAITING → SKIPPED
CALLED → NO_RESPONSE
```

Queue transfer creates movement between queues while preserving the
operational relationship to the patient/encounter.

### Queues must not own

-   Patient registration
-   Appointment booking
-   Appointment rescheduling
-   Appointment cancellation
-   Patient demographic management
-   Front-desk check-in

------------------------------------------------------------------------

# 6. Cross-Module Information

Modules are allowed to display information owned by another module.

This is necessary because the patient journey crosses module boundaries.

The rule is:

> **State and context can cross module boundaries. Ownership of actions
> cannot.**

Examples:

### Front Desk can show an appointment

``` text
John Smith
09:30
Appointment: Clinician
Status: ARRIVED
[Check in]
```

But Front Desk does not provide:

``` text
[Reschedule]
[Cancel]
[Confirm]
```

Those actions belong to Appointments.

### Front Desk can show queue state

``` text
John Smith
Checked in
Waiting for Nursing
```

But Front Desk does not provide:

``` text
[Call]
[Hold]
[Transfer]
[Complete]
```

Those actions belong to Queues.

### Appointments can show arrival

``` text
09:30
John Smith
ARRIVED
```

But the appointment page does not become the check-in workspace.

### Queues can show appointment context

``` text
A104
John Smith
Nursing
Appointment: 09:30
WAITING
```

But queue actions remain queue actions.

------------------------------------------------------------------------

# 7. The Single-Owner Rule

Every meaningful action must have exactly one canonical owner.

  Action                            Owner
  --------------------------------- --------------
  Register patient                  Patients
  Edit patient demographics         Patients
  Book appointment                  Appointments
  Reschedule appointment            Appointments
  Cancel appointment                Appointments
  Confirm appointment               Appointments
  Record no-show                    Appointments
  Record appointment arrival        Appointments
  Check in patient (encounter)      Front Desk
  Call patient                      Queues
  Recall patient                    Queues
  Start queue service               Queues
  Hold queue ticket                 Queues
  Release queue ticket              Queues
  Transfer queue ticket             Queues
  Complete queue service            Queues

If an action appears in another module, it should be represented as:

-   information,
-   status,
-   contextual link,
-   or navigation to the owning module.

It should not duplicate the action.

------------------------------------------------------------------------

# 8. Navigation

The primary navigation should remain:

``` text
Patients
Appointments
Front Desk
Queues
```

These are not four disconnected applications.

They are four different operational views of the same patient ecosystem.

### Patients

Patient-centric.

> Who is the patient?

### Appointments

Schedule-centric.

> When is the patient expected?

### Front Desk

Arrival-centric.

> Has the patient arrived and been checked in?

### Queues

Service-flow-centric.

> Where is the patient waiting or being served?

------------------------------------------------------------------------

# 9. Frontend Relationship Model

``` text
                         PATIENT
                    John Smith / ID 123
                           │
          ┌────────────────┼────────────────┐
          │                │                │
          ▼                ▼                ▼
    APPOINTMENTS       FRONT DESK         QUEUES
       "When?"          "Arrived?"         "Where?"
          │                │                │
          │                │                │
          │             CHECK-IN            │
          │                │                │
          └────────────────┴────────────────┘
                           │
                           ▼
                        SERVICE
```

Operational flow:

``` text
Appointment
     ↓
Patient arrives
     ↓
Front Desk
     ↓
Check-in
     ↓
Queue
     ↓
Nurse / Clinician / Lab / Pharmacy / Other Service
```

Walk-in flow:

``` text
Walk-in
   ↓
Front Desk
   ↓
Check-in
   ↓
Queue
   ↓
Service
```

------------------------------------------------------------------------

# 10. Front Desk Screen Architecture

Front Desk should be designed as a **daily operational board**, not as
another patient-management system.

A conceptual structure:

``` text
Front Desk
────────────────────────────────────────────

Today

Expected       Arrived       Walk-ins
   32             21             4

────────────────────────────────────────────

Today's Patients

Patient       Appointment      Status       Action

John Smith    09:00            Arrived      Check in
Mary Jones    09:15            Checked in   View
David Brown   09:30            Expected     —
Sarah Ali     Walk-in          Arrived      Check in
```

The screen should prioritize:

1.  Who is expected?
2.  Who arrived?
3.  Who needs check-in?
4.  Who has been checked in?
5.  What is the basic current state?

Do not turn this into a full appointment-management or queue-management
screen.

------------------------------------------------------------------------

# 11. Appointment Screen Architecture

Appointments should be designed around scheduling.

Conceptually:

``` text
Appointments
────────────────────────────────────────────

Calendar | List

[Book appointment]

09:00  John Smith
       Clinician
       BOOKED

09:30  Mary Jones
       Follow-up
       CONFIRMED

10:00  Sarah Ali
       Review
       ARRIVED
```

Actions remain appointment-specific:

``` text
Book
Reschedule
Cancel
Confirm
No-show
```

Arrival/check-in information may be visible as status/context.

------------------------------------------------------------------------

# 12. Queue Screen Architecture

Queues should be designed around live patient movement.

Conceptually:

``` text
Queues
────────────────────────────────────────────

Nursing
────────────────────────
A102  John Smith      WAITING
A103  Mary Jones      CALLED
A104  David Brown     IN SERVICE

Clinician
────────────────────────
B021  Sarah Ali       WAITING

Laboratory
────────────────────────
C014  Peter Smith     WAITING
```

Actions should be queue-specific:

``` text
Call
Recall
Start
Hold
Release
Skip
Transfer
Complete
```

The queue screen should make the current operational state immediately
visible.

------------------------------------------------------------------------

# 13. Patient Context

Patients should remain the canonical patient identity.

A patient can be referenced from all three operational modules.

For example:

``` text
John Smith
Patient ID: 12345
```

May appear in:

``` text
Patients
Appointments
Front Desk
Queues
```

This duplication of identity is correct.

What should not be duplicated is ownership of actions.

The user should always be able to navigate back to the patient record
when appropriate.

------------------------------------------------------------------------

# 14. Cross-Module Navigation

Use contextual navigation instead of duplicated workflows.

Examples:

### From Front Desk

``` text
John Smith
Checked in
Waiting for Nursing

[View patient]
[Open queue]
```

### From Appointments

``` text
John Smith
09:30
ARRIVED

[View patient]
```

### From Queues

``` text
John Smith
Nursing
WAITING

[View patient]
```

Links should take the user to the owning workspace when they need to
perform an action.

------------------------------------------------------------------------

# 15. Avoid These Design Patterns

Do not create:

``` text
Front Desk
 ├── Book appointment
 ├── Reschedule appointment
 ├── Cancel appointment
 ├── Check-in
 ├── Call patient
 └── Transfer queue
```

That makes Front Desk a second copy of the entire system.

Do not create:

``` text
Appointments
 ├── Book
 ├── Check-in
 ├── Call patient
 └── Transfer
```

That makes Appointments responsible for operational service flow.

Do not create:

``` text
Queues
 ├── Register patient
 ├── Book appointment
 ├── Check-in
 └── Transfer
```

That makes Queues responsible for unrelated workflows.

------------------------------------------------------------------------

# 16. Design Principle: One Owner, Many Views

The system should follow:

``` text
ONE OWNER
    ↓
CANONICAL ACTION
    ↓
OTHER MODULES MAY DISPLAY STATE
    ↓
CONTEXTUAL LINK BACK TO OWNER
```

Not:

``` text
ONE ACTION
    ↓
COPIED INTO MULTIPLE MODULES
```

This principle should be applied consistently to every new feature.

------------------------------------------------------------------------

# 17. Backend Alignment

Frontend implementation must use the existing backend contracts and
permissions.

Do not invent:

-   endpoints
-   permissions
-   actions
-   lifecycle transitions
-   entities
-   workflows

The backend audit identified strong support for:

-   Patient operations
-   Encounter/check-in lifecycle
-   Queue lifecycle
-   Appointment lifecycle

Known backend gaps must remain visible rather than being simulated in
the frontend.

Known overlaps, such as the two queue-transfer HTTP paths, should be
treated as backend contract decisions and not used as a reason to
duplicate the action in the UI.

The `[S]` suspended/in-flight backend changes are not considered stable
contracts until they are merged and verified end-to-end.

------------------------------------------------------------------------

# 18. Implementation Rule

Before implementing any new frontend feature, answer these questions:

1.  **Which module owns this feature?**
2.  **Which backend domain owns the operation?**
3.  **Which endpoint/contract performs it?**
4.  **Which permission controls it?**
5.  **Which page is the canonical place to perform the action?**
6.  **Which other modules need to display its state?**
7.  **Should those other modules show an action, a status, or only a
    link?**
8.  **Does this introduce a duplicate action somewhere else?**

If ownership cannot be clearly answered, do not implement the feature
yet. Resolve the architecture first.

------------------------------------------------------------------------

# 19. Final Mental Model

Keep this model as the foundation for the frontend:

``` text
PATIENTS
"Who?"
   │
   ├───────────────┐
   │               │
   ▼               ▼
APPOINTMENTS    FRONT DESK
"When?"         "Arrived?"
   │               │
   │               ▼
   │            CHECK-IN
   │               │
   └───────────────┤
                   ▼
                 QUEUES
                 "Where?"
                   │
                   ▼
                SERVICE
```

### The simplest rule

**Patients = who**

**Appointments = when**

**Front Desk = arrived**

**Queues = where**

**Service = what is being done**

The frontend should make these distinctions obvious without requiring
the user to understand the backend architecture.
