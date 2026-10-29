# Rule 06 — Forms & validation

Laravel validates; React renders. Every behavior below traces to a verified
backend mechanism.

## Error transport (three shapes, all server-owned)

1. **Default bag** — `$request->validate()` / FormRequests / `back()-
   >withErrors([...])` land flat in `useForm().errors` as
   `{ field: message }`. Domain controllers also report form-level failures
   here under pseudo-fields: `catalog`, `cash_session`
   (`BillableEventController:33`, `CashSessionController:40`). These are not
   flash — render them from `errors`, keyed exactly as sent.
2. **Named bags** — Fortify password update validates into the
   `updatePassword` bag (`UpdateUserPassword:31`). Inertia shares named bags
   nested (`errors.updatePassword.field`); `useForm().errors` will NOT
   contain them. Read via `bagErrors(errors, 'updatePassword')`
   (`lib/forms/errors.ts`). If a future form sends `X-Inertia-Error-Bag`,
   the default bag arrives nested under that name instead — same reader.
3. **Flash outcomes** — `status`/`error` (+ domain keys) for success/failure
   summaries; layouts render them. `withInput()` repopulates on failure
   (files excluded, e.g. `photo`).

## Form pattern (established, see `pages/Auth/*`)

`useForm` → Carbon inputs with `invalid`/`invalidText` from server errors →
a form-level `<FormErrors>` for non-field/pseudo-field messages → submit →
redirect carries fresh props + flash. Every form renders `<FormErrors>`
even when today it has only field errors — the backend may add a
pseudo-field failure tomorrow (`cash_session`, …) and it must be visible.

Every `<Form>` carries `noValidate`: native browser bubbles ("Please fill
out this field") must never preempt backend validation. `required` stays on
inputs for assistive technology — it declares, never enforces.

## Server-owned logic (never duplicated)

- Cross-field rules: `dob_precision=KNOWN` requires `date_of_birth`;
  sensitive demographic edits require `reason` (ADR-127). Render the
  backend's message; do not pre-enforce in React.
- Field limits (`max:100`, `date_format:Y-m-d`, enums) may inform input
  attributes (`maxLength`, `type="date"`) as UX hints, but submission always
  goes to the server for the verdict.
- File uploads (`photo`: jpeg/png/webp ≤ 2MB): pass `File` in `useForm` data
  (multipart is automatic); never pre-read or pre-validate bytes beyond
  basic type hints.
- No-change submissions: the backend answers `info` flash ("No changes
  detected") — but see the gap below.

## Known backend gaps (reported, not patched)

- `with('info', …)` (`PatientUpdateController:62`) and
  `with('pending_walkin_values', …)` are flashed but absent from
  `HandleInertiaRequests` shared keys — they never reach the frontend.
  Backend-owned fix when a slice needs them.
