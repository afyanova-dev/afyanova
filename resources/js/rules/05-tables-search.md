# Rule 05 — Tables, search, filtering

Every pattern below traces to a verified backend contract. No invented query
keys, no invented pagination envelopes, no reimplemented search logic.

## The contract: URL → server → echo

List pages take query in the URL, the backend filters/sorts, and echoes the
applied values back as props. Verified instances:

- `Appointments/Index`: `?date=YYYY-MM-DD` (regex + `checkdate`, default
  today) → props `date`, `appointments[]` (server-sorted by start), `types[]`
  (server-derived filter options), `metrics{}`.
- `Billing/Index`, `Pharmacy/Index`: `?patient_id=` (UUID else ignored) plus
  `?q=` (trimmed, ≤ 80 chars) → props `query` (echo), `searchResults`,
  `searchError`, plus page capability flags (`canRecordPayments`, …).

### Patient Search & Browse

- `patient.api.search` (JSON): `q|patient_number|national_id|insurance_id|
  phone|name_fragment|dob`, `page`, `per_page` ≤ 100 → `{ patients[], total,
  currentPage, lastPage }`; too-broad criteria → HTTP 200 with
  `{ patients: [], total: 0, error }` (without page metadata).

  This route has two backend modes. Any usable search criteria invoke
  `SearchPatients`; no usable criteria invoke `GetRecentPatients`, returning
  patients registered at the active facility ordered by patient creation time,
  newest first. This paginated browse view is recent registration, not recently
  accessed/search history. Both modes are restricted to the server-resolved
  active facility. Never accept a facility ID from the client to widen scope.
  Cross-facility identity resolution, if required, must be a separate,
  explicitly authorized and audited workflow.

Consequences:

1. **Query keys are backend-defined.** Never send a key the controller does
   not read; never expect an echo the controller does not send.
2. **Search classification is backend-owned.** Raw `q` goes over the wire
   (PAT- prefixes, phone-digit rules, 3-char name minimums all live in PHP).
   The frontend must not pre-classify, pre-validate, or second-guess `q`.
3. **Sort and filter options come from the server.** Sorted arrays, `types[]`,
   metrics — render them, do not recompute them.
4. **Actions are gated by the page's `canX` flags** (Rule 02), not by row
   contents.

## Frontend patterns

- **Page search** (`?q=`, `?date=`): Carbon `Search` control, value applied
  via `applyListQuery` (`lib/lists/query.ts` → `router.get` on the current
  path, empties stripped, state+scroll preserved). Typing does not fire a
  visit per keystroke — submit/apply explicitly, or debounce only the
  JSON-typeahead case below.
- **JSON typeahead** (`patient.api.search`, calendar): control stays local,
  debounced via `useDebouncedValue` (default 300ms), fetched with `getJson`.
  Do not mirror the backend's `q` classification in React. A blank query is a
  deliberate request for the recent-registration page, not an empty search;
  a one-character name query also falls back to that page, while a two-
  character name fragment returns the backend's `error` envelope. Exact
  identifiers do not share the name minimum. The Patients Inertia page
  presents browse distinctly from targeted search and uses the same
  server-side resolver semantics.
- **Tables**: Carbon `DataTable` per domain — columns belong to the slice,
  never a universal table. Composition: `PageHeader` → search/filter controls
  → `DataTable` → `Pagination` → `EmptyState`/`ErrorState`.
- **Pagination UI**: Carbon `Pagination` mapped as `totalItems = total`,
  `page = currentPage`, `pageSize` = the `per_page` the caller sent (the
  endpoint does not echo it; default 15, max 100). Page changes go through
  `applyListQuery({ page })` for Inertia lists, or re-`getJson` for widgets.
- **Soft failures**: `{ error }` / `searchError` props render in `ErrorState`
  with the backend's message; empty results render `EmptyState`. Never invent
  failure text the backend did not send.

## Explicitly not built

No universal table, no generic filter-bar, no client-side search/sort over
server datasets. The two helpers above (`applyListQuery`,
`useDebouncedValue`) are the whole shared surface until a second slice proves
more is needed.
