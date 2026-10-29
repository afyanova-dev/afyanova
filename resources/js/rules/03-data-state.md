# Rule 03 — Data & state architecture

Laravel is the source of truth. Inertia is the primary transport. Four kinds
of state, four homes — never mix them.

## The four states

| State | Home | Examples |
|---|---|---|
| Server state | Inertia page/shared props | patients, queues, chart sections, facilities, sessions |
| UI state | Local `useState` | modal open, selected row, expanded section, drawer |
| Form state | Inertia `useForm` | field values, validation errors, processing, reset/cancel |
| URL state | Query string via `router.get` | search, filters, sort, page, tab |

No global store (Redux/Zustand/React Query/SWR — all banned in
`eslint.config.js`). No `services/`, `repositories/`, `api/` layers. If
`useState` + `useForm` + URL + props cannot solve it, the requirement — not
the architecture — is what gets reviewed first.

## Data flow

```text
Laravel → controller/Inertia response → page props → page → feature/UI
Mutations: useForm/router → Laravel (authz + validation + logic) → redirect
  → fresh props → UI. Server-confirmed; no optimistic clinical writes.
```

## Inertia conventions

- Page props are full snapshots (arrays, section objects); there is no
  normalized cache — do not keep a second copy in state.
- Validation: FormRequest 422s surface as `useForm().errors` automatically.
  Field messages render from `errors.*`; never duplicate rules client-side as
  truth (client checks are UX hints only).
- Flash: `status`/`error` render once in the app shell as dismissible overlay toasts, keyed by `flash.id`; pages do not repeat them.
  Domain keys (`registered_patient`, `duplicate_review`, …) are consumed by
  the owning slice when it lands — see Rule 01 placement.
- Partial reloads (`router.reload({ only })`) and `preserveState`/`preserveScroll`
  are opt-in per interaction, never global defaults.

## The JSON exception

Three session-authenticated JSON endpoints exist for widget-style reads where
an Inertia visit is wrong (typeahead, calendar grids):

### Patient Search & Browse

- `GET patient.api.search` (route middleware `web`, `auth`, `verified`;
  controller additionally requires `patient.patients.read` and an active
  facility context). Optional params:
  `q|patient_number|national_id|insurance_id|phone|name_fragment|dob`,
  `page` (default 1), `per_page` (default 15, clamped to 1–100). No sorting
  params or separate patient-list route exist.

  With usable criteria, it searches normalized patient number, verified
  identifiers, phone digits, or name fragments (minimum 3 characters); `dob`
  is only a rank boost, not a result filter. Missing, empty, or
  whitespace-only criteria dispatch `GetRecentPatients`: a paginated listing
  restricted to the server-resolved active facility and ordered by patient
  creation time, newest first. This is recent registration, not recent access.
  Both search and browse modes require active facility registration; the
  facility is resolved server-side, never accepted from client input. A
  DOB-only request or a two-character name fragment instead returns the error
  envelope. Do not confuse the browse behavior with a separate
  patient-directory endpoint. Cross-facility identity resolution must use a
  separate, explicitly authorized and audited workflow.
  Creating a new patient also records that patient's initial participation at
  the active facility, so the new record is immediately available to facility
  workflows.

  Success shape: `{ patients[], total, currentPage, lastPage }`; `per_page`
  is not echoed. Each patient includes `patientId`, `patientNumber`,
  `displayName`, `sex`, `status`, `isProvisional`,
  `hasAccessibleFacilityRegistration`, `survivingPatientId`, `dateOfBirth`,
  `dobPrecision`, `formattedDob`, `age`, `photoUrl`, and `activeEncounter`.
  The last field is populated only when the caller also has
  `encounter.encounters.read`. Too-broad search criteria are caught by the
  controller and return HTTP 200 with
  `{ patients: [], total: 0, error }` (no page metadata).

- `GET appointments.api.calendar` → `{ days[] }` (`start`/`end`, ≤ 62 days;
  failures return `{ days: [] }`).
- `GET scheduling.appointment-options` → appointment booking options.

Fetch them only through `lib/http/json.ts` (`getJson`: same-origin cookies,
`Accept: application/json`, `X-Requested-With`, typed throws). No other
`fetch()` for domain data. Soft 200-with-`error` responses are checked by the
caller — the helper must not invent error semantics per endpoint.

## Lists, pagination, URL state

- Pagination is backend-shaped, not Laravel-default: endpoints hand-map
  LengthAwarePaginator to `{ total, currentPage, lastPage }` (+ a named items
  array). Never assume `meta`/`links`. `lib/pagination/page.ts` holds the
  shared shape and readers.
- Patient Search & Browse is an Inertia list using the verified API query
  behavior through shared server query resolution; it does not fetch JSON
  into React. Its route-level page is under `resources/js/pages/`; domain
  components and types live under `resources/js/features/patients/`. The URL
  owns `q`, `page`, and `per_page`; results remain server snapshots.
- Tables: Carbon table primitives per domain; no universal table component.

## Forms and mutations

Established pattern (see `pages/Auth/*`): `useForm` → Carbon inputs with
`invalid`/`invalidText` from server errors → `post/patch/delete` → redirect
carries fresh props + flash. Mutations that change state use POST/PATCH/DELETE
to real routes; GET never mutates.

## Types and transformation

Types mirror backend DTO/prop shapes 1:1 (`types/` for shared, feature-local
`types.ts` later). No repository/view-model layers. Statuses, sexes,
precisions are backend strings/enums (`Sex`, `DobPrecision`, `*_status`
columns) rendered through `Status`/plain text — never a frontend registry of
clinical states. Canonical URLs live in `app/routes.ts` builders
(`patientChartUrl`, …): the backend keeps legacy + canonical URLs serving one
controller and requires TS to point at the canonical one.

## Patient context (deferred)

`/patient/{id}/chart/{tab?}` composes profile + per-domain feeds from one
controller. When the chart slice lands: one thin chart page composing
per-domain section components that each own their section's props — never one
giant file, never per-feature patient headers. Until then, no patient-context
code exists and none is built speculatively.
