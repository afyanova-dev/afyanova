# Rule 10 — Clinical workflow

Statuses pass through; transitions are verified. Deliberately thin until
real slices land.

- **Display**: render backend status strings as-is through `Status` (or plain
  text). No central registry, no invented states (`almost_completed` is the
  canonical example of what never ships). If a slice needs grouping
  (e.g. counts of scheduled vs. checked-in), the grouping mirrors a
  backend-verified set — see `AppointmentsController:101-107`
  (`REQUESTED/BOOKED/CONFIRMED`, `ARRIVED/IN_SERVICE`, `COMPLETED`,
  `NO_SHOW`) — and lives in the slice, never in shared code.
- **Transitions**: every workflow action (arrive, check in, complete,
  cancel, transfer…) maps 1:1 to a real backend route + permission, gated by
  the page's `canX` flags or `resourceCan` (Rule 02). "Clinically logical"
  is not a source — `route:list` plus the controller are.
- **Think in workflows, build in slices**: appointment → check-in →
  encounter → orders → results is the shape, but each step ships only against
  its verified controller, DTO, and route. No speculative workflow
  orchestration layer.
