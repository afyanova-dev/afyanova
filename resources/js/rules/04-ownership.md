# Rule 04 — Ownership boundary

Do not invent a frontend architecture that duplicates or fights the backend.

| Laravel owns | React/Inertia owns |
|---|---|
| Data, records, snapshots | Presentation, layout, Carbon UI |
| Authorization, policies, roles, scopes | Visibility driven by `can()` / `resourceCan()` |
| Validation rules and errors | Form UX: inputs, hints, displaying server errors |
| Business rules, workflows, transitions | Orchestrating the backend workflow step by step |
| Clinical state and statuses | Displaying backend states, never inventing them |
| Pagination, search, sort, totals | Rendering controls; holding applied values in the URL |
| Flash/session outcomes | Rendering notifications exactly once |

## Decision test

Before adding frontend logic, ask: *does the backend already decide this?*
If yes, consume the decision (props, `can()`, `resourceCan()`, flash) — do
not recompute it. Frontend derivations of backend-owned answers rot on every
backend change and become a second, wrong backend.

Concretely banned (machine-enforced in `eslint.config.js`):

- Direct `fetch()` outside `lib/http/` — the only fetch funnel is `getJson`
  (Rule 03's JSON exception). Everything else is Inertia.
- Role equality/`includes` checks (Rule 02) — roles are backend-owned.
- Client-state/transport libraries (Rule 01) — no second source of truth.

Concretely required: new UI states, statuses, permissions, or endpoints must
trace to a verified backend contract (route, controller prop, DTO, policy
key). "Clinically logical" is not a source — the backend is.

Rules 01–03 are this boundary applied: placement keeps domain logic out of
generic layers, authorization consumes instead of decides, and data flows one
way from Laravel to React.
