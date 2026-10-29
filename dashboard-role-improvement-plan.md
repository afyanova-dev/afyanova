# Dashboard and Role-Workflow Improvement Plan

## Goal

Make the post-login dashboard accurate, useful for each staff workflow, and clear when information is unavailable. Dashboard counts must remain facility-scoped and permission-gated, including for users with multiple roles.

## Phase 1 — Correct the existing dashboard metrics

Start here because additional role-specific cards would build on metrics whose current labels and query behavior do not consistently match.

**Status: implemented.** The dashboard now excludes cancelled, rescheduled, and no-show appointments; counts arrival timestamps for the facility day regardless of later encounter status; and counts today's ARRIVED/ACTIVE encounters without a list limit.

- Define the meaning and status rules for **Scheduled today**, **Arrived today**, and **Active visits**.
- Make each query follow its definition, including facility and local-day boundaries.
- Ensure an arrival total does not silently omit encounters that have moved to another status.
- Ensure active visits represent the intended active statuses and date range, and are not truncated by a list limit.
- Exclude terminal appointment states where the metric is intended to count appointments that are still scheduled.
- Add focused backend tests for status transitions, terminal states, date boundaries, facility isolation, and high-volume counts.

**Exit criteria:** Every existing card's label matches its query; test data verifies the expected count, including edge cases.

## Phase 2 — Resolve workspace availability and navigation

**Status: implemented with a Laboratory limitation.** Billing has a protected backend Inertia route and frontend pages, so it is now included in built workspace navigation for users with `billing.access`. The Laboratory module currently provides backend domain/application functionality but no workspace route or frontend page; it remains unavailable in navigation until that UI slice is built.

- Confirmed Billing is implemented: `/workspaces/billing` renders `Billing/Index`, its catalog route renders `Billing/Catalog`, and the backend controller checks `billing.access`. Added Billing to the built-workspace list; users without the permission still do not see its navigation link.
- Confirmed there are no Laboratory web routes or frontend pages. The backend Laboratory domain exists, but its role does not yet have a Laboratory workspace to open; do not treat Procurement as a substitute.
- Added navigation tests for Billing discoverability and preserved its permission gate.

**Exit criteria:** Met for available workspaces. Laboratory workspace access remains pending a frontend and route implementation.

## Phase 3 — Add role-relevant, actionable summaries

Build summaries from the existing workflow queues and link each card to the exact queue it represents. Only add a card when a reliable, permission-checked source exists.

**Status: partially implemented.** The dashboard now has permission-scoped, facility-scoped work summaries for triage, clinician consultations, prescription review, pharmacy fulfillment, the signed-in cashier's active cash session, and billable events needing review. Current workload cards are separated from day totals and link to their corresponding workspaces. Laboratory remains blocked on its missing workspace. A general cashier payment backlog and facility-wide outstanding-invoice count are not included because the current billing workspace reads patient-selected accounts rather than exposing a suitable facility queue.

- **Receptionist:** appointments due, arrivals, and patients awaiting the next front-desk step.
- **Nurse:** triage workload and waiting-time indicators, linked to Nurse Station.
- **Clinician:** patients awaiting clinician action and clinically relevant queue priority indicators.
- **Pharmacist:** prescriptions or orders awaiting dispensing or pickup.
- **Cashier / Billing:** payment and billing work awaiting action, scoped to the user's permissions.
- **Laboratory Technician:** orders awaiting acceptance, specimens to receive, and results awaiting entry or verification, if those queues are implemented.
- For multi-role users, combine only the summaries they are authorized to see; avoid duplicate or misleading counts.

**Exit criteria:** Partial. Nurse, Clinician, Pharmacy, cashier-session, and Billing Admin billable-event summaries are implemented. Reception already has today's arrivals and appointments linked to its workspace, but a separate next-step workload is not added because no reliable, distinct front-desk pending-work read model has been verified. Richer priority/wait indicators, facility-wide cashier payment/invoice workload, and Laboratory remain outstanding.

## Phase 4 — Make dashboard states explicit and protect behavior with tests

- **Partially implemented.** The dashboard now explicitly reports restricted summaries and summaries that could not be loaded. An empty work-queue state appears when all available work queues have zero items; individual count cards continue to show their exact counts.
- Added backend persona coverage for Receptionist, Nurse, Clinician, Pharmacist, Cashier, Billing, and Laboratory Technician permissions. Lab correctly has no dashboard card while its workspace is unavailable.
- Added backend coverage for a user whose Nurse/Clinician dashboard permissions come from separate role assignments, plus a facility-scope test proving queue entries in another facility do not affect the active facility's triage count.
- Added focused UI coverage for queue links and the empty, restricted, and unavailable states.
- Remaining: facility-isolation tests for each individual card source. Laboratory remains unavailable until its workspace exists.

**Exit criteria:** Partial. Users can tell whether displayed work queues are empty, restricted, or unavailable. Persona visibility, multi-role composition, and triage facility isolation are covered; verify facility isolation for the remaining card sources.

## Delivery order

1. Phase 1 — metric correctness.
2. Phase 2 — workspace readiness and discoverability; can proceed in parallel with Phase 1 after the workspace status is confirmed.
3. Phase 3 — role-specific summaries, depending on verified workflow queues and Phase 2 readiness.
4. Phase 4 — add relevant tests and explicit states alongside each implementation; complete the cross-role verification at the end.

## Scope and implementation notes

- This plan covers the live dashboard and the workflows it links to; it does not assume the older `DashboardPresenter` is active.
- Preserve existing permission checks and facility scoping; do not expose patient-identifying details in summary cards.
- Prefer existing query handlers, API contracts, and queue read models over introducing parallel dashboard-only business logic.
- Keep each phase small enough to validate with targeted tests before moving on.
