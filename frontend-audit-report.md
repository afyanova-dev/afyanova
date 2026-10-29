# AfyaNova EMR — Frontend Audit Report

| Field | Value |
|---|---|
| Audit date | 2026-10-23 |
| Scope | `resources/js` (React/Inertia frontend), its CSS, its test/build tooling, and the PHP boundary that feeds it |
| Repository state | No Git metadata available; audit is of the working tree as found |
| Mode | Read-only. No application file, dependency, config, or database row was modified |
| Build artifact | Produced only in `/tmp/opencode/afyanova-build` for measurement |
| Audience | Engineering lead, clinical safety owner, product owner |

---

## 1. Method

1. Inventoried the frontend: file counts, line counts, dependency versions from `node_modules`/`composer.lock`.
2. Read the project's own contracts in `resources/js/rules/01–12`, `eslint.config.js`, `frontend-foundation-*.md`, and `SECURITY-REVIEW.md`.
3. Traced the full request path for representative screens: route → controller → Inertia props → page → feature component → shared UI → CSS tokens.
4. Inspected every page and feature with clinical, financial, or identity impact; skimmed the rest for pattern violations.
5. Ran the available gates (`lint`, `types`, `test`, production build) and captured exact results.
6. Quantified systemic patterns with repository-wide searches (form elements, inline styles, date formatting, tables, a11y attributes).
7. Classified findings as **confirmed defect**, **potential risk**, or **architectural recommendation**, with a confidence level.

Not performed: Playwright E2E (needs a served/seeded app), PHPUnit/Pest, manual screen-reader testing, real-device testing, load testing, penetration testing.

---

## 2. Scope inventory

| Metric | Value |
|---|---|
| Non-test `.tsx` files | 170 |
| Total frontend lines (`.tsx`, non-test) | 35,492 |
| Test files (`*.test.ts(x)`, `*.spec.ts`) | 58 |
| Test cases (`it(` / `test(`) | 566 |
| CSS files | 17 (`app.css`, `foundation.css`, `tokens.css`, 14 legacy) |
| CSS lines | 6,832 (4,923 in `legacy/`) |
| Files > 1,000 lines | 4 |
| Files > 700 lines | 10 |
| Inertia `<Form>` elements | 23 |
| Other native `<form>` elements | 12 |
| `DockedPanel` instances | 53 |
| `DockedPanel` submit forms | 47 |
| `style={{…}}` occurrences | 406 across 56 files |
| `useForm` call sites | 104 |
| `useEffect` / `useMemo` / `useCallback` / `React.memo` | 74 / 68 / 21 / 0 |
| Permission keys transmitted per response | 241 |
| JS build chunks | 95 |
| CSS bundle | 1.08 MB raw / 119,136 bytes gzip |
| Largest JS chunk | 320.54 kB (`es`, Carbon core) / 99,394 bytes gzip |
| Production build time | 1.78 s |

**Ten largest non-test components**

| Lines | File |
|---:|---|
| 1,424 | `resources/js/features/inventory/components/panels/GoodsReceiptWorkbenchPanel.tsx` |
| 1,423 | `resources/js/features/procurement/OrdersSection.tsx` |
| 1,398 | `resources/js/pages/Admin/Identity/RolePromotions.tsx` |
| 1,063 | `resources/js/features/procurement/RequestsSection.tsx` |
| 988 | `resources/js/features/service-queues/components/QueueBoard.tsx` |
| 865 | `resources/js/features/hr/components/StaffProfile.tsx` |
| 822 | `resources/js/features/patients/forms/RegisterPatientForm.tsx` |
| 737 | `resources/js/features/pharmacy/components/TranscribePanel.tsx` |
| 723 | `resources/js/features/inventory/components/InventoryWorkspace.tsx` |
| 716 | `resources/js/features/patients/components/PatientChart.tsx` |

---

## 3. Verification results

| Gate | Command | Result | Notes |
|---|---|---|---|
| Lint | `npm run lint` | **Pass** | `eslint resources/js --max-warnings=0`; no output |
| Types | `npm run types` | **Pass** | `tsc --noEmit`; strict + `noUncheckedIndexedAccess` |
| Unit/component | `npm test` | **1 failed / 57 passed files** | **554 passed / 1 failed tests** |
| — failure | `navigation.test.tsx:654` | 20 s timeout | "renders primary task as right-side icon buttons…" |
| — isolated rerun | same test | **Pass in 16.08 s** | Load-dependent; 2 Vitest worker `onTaskUpdate` timeouts also seen |
| Production build | `npx vite build --outDir /tmp/opencode/afyanova-build --emptyOutDir` | **Pass** | 1.78 s; 95 chunks; 2.9 MB total |
| E2E | `npm run test:e2e` | Not run | Requires `php artisan serve --env=testing` + seeded data |
| Backend | `composer test` | Not run | Out of scope |

**Interpretation:** static gates are genuinely green. The single failure is a timeout in a 16-second test under full-suite load, not a functional regression — but it is treated as a finding (FR-22) because a 20 s timeout on a header test is a poor signal.

---

## 4. Stack as installed

| Package | Declared | Installed |
|---|---|---|
| react / react-dom | `^19.0.0` | 19.2.8 |
| `@inertiajs/react` | `^3.7.1` | 3.7.1 |
| `inertiajs/inertia-laravel` | — | 3.3.4 |
| `@carbon/react` | `^1.117.0` | 1.117.0 |
| vite | `^8.0.0` | 8.2.2 |
| vitest | `^3.2.7` | 3.2.7 |
| typescript | `^5.7.0` | 5.9.3 |
| `@playwright/test` | `^1.49.0` | 1.62.1 |
| laravel/framework | — | 13.29.0 |

**Unused declared dependencies:** `sonner`, `laravel-echo`, `pusher-js` — zero imports under `resources/js` (FR-20).

---

## 5. Architecture map

```
routes/web.php + app/Domains/*/routes.php
        │
        ▼
Controllers → Inertia props (HandleInertiaRequests shares app/auth/flash/correlationId)
        │
        ▼
app.tsx  ── import.meta.glob('./pages/**/*.tsx')  → per-page chunk, no client fallback
        │
        ▼
pages/*  (compose features + layout)
        │
        ├── layouts/AppLayout  (Header, SideNav, FlashMessages, Carbon <Content id="main-content">)
        │
        ├── features/*         (domain logic, panels, tables, forms)
        │        │
        │        ├── components/ui      (CarbonDataTable, PageHeader, Status, EmptyState, ErrorState, FormErrors)
        │        ├── components/panels  (DockedPanel — 47 submit forms)
        │        ├── components/workspace (useWorkspaceGuard, DiscardChangesModal)
        │        └── lib/http           (getJson/sendJson — the only fetch)
        │
        └── lib/formatting              (dates.ts UTC | facility-time.ts zone | 23 files browser-local)
```

### Enforcement mechanisms that work

- `eslint.config.js:8-40` bans transport libraries and role-based branching.
- `eslint.config.js:59-111` enforces import direction per layer.
- `resources/js/test/structure.test.ts` freezes the top-level tree.
- `composer conform` regenerates `resources/js/shared/types/generated.ts` and fails on drift.

### Enforcement gaps

- No rule against inline styles (406 occurrences).
- No accessibility plugin (`eslint-plugin-jsx-a11y` absent).
- No rule requiring `noValidate` on `<form>`.
- Generated types are produced but never consumed by the frontend.

---

## 6. Scorecard

Scores are 1–10, anchored to cited evidence. Confidence reflects verifiability.

| # | Category | Score | Rationale (evidence) | Confidence |
|---|---|---|---|---|
| 1 | Architecture & boundaries | **8** | Enforced imports; one HTTP funnel; route builders; frozen tree | High |
| 2 | TypeScript & type safety | **9** | `strict`, `noUncheckedIndexedAccess`; 0 `@ts-ignore`; 1 `as unknown as`; `any` banned | High |
| 3 | React patterns & state | **7** | Server props + `useForm`; no global state; no `memo`/compiler; 8 dep-suppression comments | Medium |
| 4 | Design system & CSS | **5** | Carbon + tokens, but 406 inline styles and 4,923 legacy CSS lines | High |
| 5 | Clinical workflow safety | **5** | Strong registration draft and pharmacy checks; no clinical autosave, no allergy in context, unconfirmed completion | Medium |
| 6 | Accessibility | **6** | Skip links, labels, `invalidText`, semantic tables; nested `<main>`, no focus management, no a11y lint | Medium |
| 7 | Forms & validation | **6** | 249 `invalid=` wirings, 42 `FormErrors`; 21/36 forms lack `noValidate`; 29/47 panels lack in-panel errors | High |
| 8 | Security & privacy | **7** | CSRF, same-origin, no `innerHTML`, no tokens in storage; identifiers in URLs; 241-key map | Medium |
| 9 | Performance | **7** | 95 chunks, lazy images; 1.08 MB CSS, no timeouts, serial paging | Medium |
| 10 | Testing | **5** | 566 test cases but 0 tests on Clinician/Nurse Station; 9 E2E; no coverage config | High |
| 11 | Maintainability | **5** | Clear conventions; 4 files >1,000 lines (largest 1,424); no formatter; doc drift | High |
| 12 | Resilience & observability | **5** | Inertia error dialog + correlation ID; no error boundary, toasts never expire, no request timeout | Medium |

**Weighted overall: 6.4 / 10.**

---

## 7. Findings

Severity definitions used here:

- **CRITICAL** — confirmed defect with direct, likely patient harm or data corruption on a normal workflow.
- **HIGH** — confirmed defect with realistic harm, or a workflow that cannot be completed as designed.
- **MEDIUM** — confirmed defect with bounded impact, or a systemic inconsistency likely to cause future defects.
- **LOW** — cosmetic, local, or low-probability issue.
- **INFORMATIONAL** — observation, no action required now.

> **No CRITICAL findings.** Several HIGH findings sit on patient-safety or financial paths, but each retains a downstream control (server revalidation, pharmacist attestation, permission checks). They are rated HIGH rather than CRITICAL for that reason, not because the exposure is small.

---

### HIGH

#### FR-01 — Billing is a live workflow that navigation cannot reach

**Severity:** HIGH · **Area:** Navigation / Revenue cycle · **Confidence:** High (confirmed inconsistency)

**Evidence**

- `resources/js/layouts/AppLayout/navigation.ts:40` — `BUILT_WORKSPACES` omits `billing`:
  ```ts
  export const BUILT_WORKSPACES: readonly WorkspaceId[] = ['dashboard', 'admin', 'patients', 'appointments', 'reception', 'serviceQueues', 'nurseStation', 'clinician', 'hrStaff', 'pharmacy', 'inventory', 'procurement'];
  ```
- The comment above it (`navigation.ts:36-39`) says: *"Backend routes exist for all eight workspaces… Move an id here when its slice lands."*
- The slice has landed:
  - `app/Domains/Billing/routes.php:20-29` registers `/workspaces/billing` and catalog routes.
  - `resources/js/pages/Billing/Index.tsx` (427 lines) and `resources/js/pages/Billing/Catalog.tsx` exist.
  - `resources/js/test/cashier.test.tsx` and `resources/js/test/billing-catalog.test.tsx` exercise them.
  - `app/Domains/Billing/BillingServiceProvider.php` is registered in `bootstrap/providers.php`.
- The stale test comment at `resources/js/test/dashboard.test.tsx:59` still asserts the opposite: *"Patients has a page; billing does not yet (BUILT_WORKSPACES)."*
- No link to `/workspaces/billing` exists anywhere in the shell: `grep -rn "workspaces.billing" resources/js --include=*.tsx` returns only `pages/Billing/**` and tests.
- Pharmacy handover depends on billing clearance: `resources/js/features/pharmacy/components/PickupPanel.tsx:73` — *"Handover is blocked until the billing clearance for this dispensing is settled."*

**Impact**

A cashier cannot reach the till, invoices, or payment allocation through the product. Patients are told at pharmacy that payment must be settled, but the screen that settles it is only reachable by typing a URL. Either a live revenue workflow is orphaned or dead code is being maintained and tested. Both are unacceptable states.

**Remediation**

1. Decide and record the intent in `navigation.ts` (one sentence).
2. If live: add `'billing'` to `BUILT_WORKSPACES`, keep the workspace entry in `WORKSPACES` (already present at `navigation.ts:105-110`), and update the assertion/comment in `dashboard.test.tsx:59-61` to expect the Billing link.
3. If deliberately staged: remove the workspace from `WORKSPACES`, remove the routes from `app/Domains/Billing/routes.php`, and add an explicit `not yet enabled` route guard returning 404. Do not leave a reachable page with no entry point.
4. Add a navigation regression test: for a user with `billing.access`, `workspaceNavigation()` must include `billing` and the header/sidebar must render a link.

**Acceptance criteria**

- A user with `billing.access` can reach `/workspaces/billing` in ≤ 2 clicks from any page.
- `workspaceNavigation('/workspaces/billing', can)` marks Billing active.
- No test asserts the workspace is absent while its page exists.

**Effort:** 0.5 day (either direction), plus 1–2 days if the page is migrated (FR-14).

---

#### FR-02 — Clinical documentation is lost on navigation; only registration autosaves

**Severity:** HIGH · **Area:** Clinical workflow / Data durability · **Confidence:** High

**Evidence**

- `resources/js/components/workspace/useWorkspaceGuard.ts:14-24` intercepts **only** switches between panels inside one page:
  ```ts
  const requestSwitch = useCallback((action: () => void) => {
    if (dirty) { setPendingSwitch(() => action); } else { action(); }
  }, [dirty]);
  ```
- There is no navigation or unload guard anywhere:
  - `grep -rn "beforeunload|router.on('before'|useBeforeUnload" resources/js` → **0 results**.
  - No `router.on('before')` handler in `app.tsx`, `bootstrap.ts`, or any layout.
- Clinical forms are plain `useForm` state with no persistence:
  - `resources/js/pages/Clinician/Show.tsx:75-108` (note, diagnosis, plan, procedure, disposition).
  - `resources/js/pages/NurseStation/Show.tsx` (vitals, triage, handoff).
- The registration form, by contrast, has the full pattern:
  - debounced save at `RegisterPatientForm.tsx:289-322` (700 ms),
  - `sendBeacon` on `pagehide` at `:324-341`,
  - explicit status `saved | saving | unsaved | error` at `:273-275`,
  - user-visible messages at `:474-477`.

**Impact**

A clinician who clicks a sidebar link, presses Back, refreshes, or is signed out mid-note loses the entire note, diagnosis rationale, or plan. These are the highest-value keystrokes in the product and have the weakest durability. Registration — lower-value, higher-volume data — is better protected than clinical documentation.

**Remediation**

1. Extract the registration draft mechanism into `resources/js/hooks/useServerDraft.ts`:
   - debounced save, serialized in-flight saves, `sendBeacon` on `pagehide`,
   - `restore` on mount when a draft exists,
   - status returned to the caller.
2. Use it for consultation notes first (`Clinician/Show.tsx` note form), then diagnosis and plan, then triage.
3. Register a navigation blocker:
   - `router.on('before', (event) => { if (dirty) { event.preventDefault(); openConfirm(); } })` for Inertia visits, and
   - `beforeunload` for tab close/reload (with `event.preventDefault()` + `returnValue`).
4. Do not block on *every* keystroke; block only when the form has unsaved content and the action is a navigation, not a submit.

**Acceptance criteria**

- Typing ≥ 20 characters into a consultation note and clicking "Patients" raises a confirm dialog; choosing "Stay" preserves the text verbatim.
- Reloading within 7 days restores the note draft.
- A failed draft save shows the same inline error state as registration.
- No draft is created for an empty form.

**Effort:** 3–5 days (hook + 4 forms + tests).

---

#### FR-03 — "Complete visit" is irreversible and unconfirmed

**Severity:** HIGH · **Area:** Clinical workflow / Reversibility · **Confidence:** High

**Evidence**

- `resources/js/pages/Clinician/Show.tsx:508-517`:
  ```tsx
  <Button size="lg" renderIcon={Checkmark}
    kind="danger"
    onClick={completeEncounter}
    disabled={!dispositionSaved || completing}
    style={{ marginTop: '1rem', marginInlineStart: '0.5rem' }}
  >
    {completing ? 'Completing…' : 'Complete visit'}
  </Button>
  ```
- The handler at `:178-185` posts immediately with no confirmation step.
- The same section also contains "Save disposition" (`:487-506`), and a message telling the clinician to save a disposition first (`:518-520`). Two adjacent actions, one reversible, one not, distinguished only by `kind="danger"`.
- No undo or reopen affordance exists in the UI.

**Impact**

A misclick finalizes the encounter and closes documentation. In a busy clinic this is the kind of action that gets clicked while reaching for "Save disposition". `danger` styling also communicates "delete", which is the wrong semantic for a clinical finalization.

**Remediation**

1. Wrap the call in the existing `ConfirmDialog` (`resources/js/components/feedback/ConfirmDialog.tsx`):
   - heading "Complete this visit?"
   - body listing what is being finalized (diagnoses count, plan present/absent, disposition value),
   - confirm label "Complete visit".
2. Change `kind` from `danger` to `primary` once confirmed, and reserve `danger` for destructive actions.
3. Keep the server-side guard that refuses completion without a saved disposition; do not rely on the confirm dialog for correctness.
4. Optionally add a "reopen encounter" capability for supervisors, with reason + audit — a backend decision, flagged here.

**Acceptance criteria**

- Clicking "Complete visit" never posts without an explicit confirmation.
- The confirmation states the disposition being finalized.
- Cancelling leaves the encounter and form state untouched.
- A UI test asserts the POST is not called until confirm is pressed.

**Effort:** 0.5 day + test.

---

#### FR-04 — Billing money actions can be double-submitted; the backend cannot deduplicate them

**Severity:** HIGH · **Area:** Financial integrity · **Confidence:** High

**Evidence**

- `resources/js/pages/Billing/Index.tsx` uses native forms and `router.post` with no processing state and no disabled control:
  - record payment: `:125-134`, form at `:307-338`,
  - allocate payment: `:136-146`, form at `:357-403`,
  - issue invoice for a charge: `:148-154` (a bare `Button` `onClick`).
- `RecordPayment` accepts an idempotency key (`app/Domains/Billing/Application/Command/RecordPayment.php:38`):
  ```php
  public ?string $idempotencyKey = null,
  ```
- The controller never supplies one (`app/Domains/Billing/Presentation/Http/Controller/PaymentController.php:78-85`), and the frontend never sends one.
- By contrast, inventory already does this correctly: `crypto.randomUUID()` idempotency keys in `GoodsReceiptWorkbenchPanel.tsx:166`, `MoveStockPanel.tsx:39`, `RequestAdjustmentPanel.tsx:76`, and 6 other panels.

**Impact**

A double-click, an impatient second click, or a retried request records two payments and mints two receipts against the same account. The same applies to allocation and invoice issuance. Inventory is protected against exactly this; billing is not.

**Remediation**

1. Convert the billing forms to `useForm` (or add an explicit `processing` state) and disable the submit button while in flight.
2. Generate `payment_id`/`allocation_id`/`idempotency_key` with `crypto.randomUUID()` on mount, not per submit.
3. Pass `idempotency_key` through `PaymentController` into `RecordPayment`/`AllocatePayment`, and honour it server-side (the command layer already declares the parameter).
4. Add a Playwright test that double-clicks "Record payment" and asserts exactly one payment row.

**Acceptance criteria**

- A rapid double-click produces exactly one POST (UI) and one payment (server).
- Retrying the same idempotency key is a no-op.
- Submit buttons show "Recording…" and are disabled until the response resolves.

**Effort:** 1–2 days frontend, 1 day backend.

---

#### FR-05 — Allergy status is absent where prescribing happens, and no automated safety check exists

**Severity:** HIGH · **Area:** Medication safety · **Confidence:** High

**Evidence**

- Zero allergy references in the encounter UI:
  - `grep -rin "allerg" resources/js/pages/Clinician/Show.tsx resources/js/pages/NurseStation/Show.tsx resources/js/features/clinical/` → **0 results**.
- Allergies are a separate chart tab (`PatientChart.tsx:48`) and surface only in pharmacy dispensing (`DispensePanel.tsx:288-295`).
- The backend states the limitation explicitly in `app/Domains/Pharmacy/Application/Command/ValidatePharmacyOrder.php:17-19`:
  > *"A pharmacist attestation that the prescription is clinically sound and cleared for fulfilment. Pharm.2a runs NO automated safety checks (no catalog, no allergy / interaction engine — those are later increments)."*
- The dispense panel does the right thing — it displays recorded allergies as an `role="alert"` block — but that is one screen downstream of the prescribing decision.

**Impact**

A prescriber orders medication without seeing a recorded allergy. The only human checkpoint is the pharmacist at dispense, on a different screen, after the order exists. This is the highest-consequence gap in the product. It is a documented scope decision, not an accidental omission — which is why it is HIGH, not CRITICAL.

**Remediation**

1. Short term (days): add a read-only allergy strip to `QueuePatientBanner` (used by Clinician and Nurse Station), sourced from the existing patient allergy query. Render "No recorded allergies" explicitly when empty, never a blank.
2. Medium term: require an explicit acknowledgement in the order/prescription form when the patient has ≥ 1 active allergy.
3. Long term: catalogue + interaction engine at order entry, with severity and override reasons recorded in the audit trail.

**Acceptance criteria**

- The encounter header shows allergy count and substances for any patient with recorded allergies.
- Allergy data failure renders "Allergy status unavailable", not an empty list.
- A prescriber cannot submit a new medication order without seeing the allergy strip.

**Effort:** 2 days (strip), 1–2 weeks (acknowledgement), multi-sprint (CDS).

---

#### FR-06 — The two most safety-critical pages have no tests; medication and payment have no E2E

**Severity:** HIGH · **Area:** Test coverage · **Confidence:** High

**Evidence**

- 58 test files / 566 test cases; no `it.skip`, no `it.only`, no snapshots.
- `resources/js/pages/Clinician/Show.tsx` (528 lines) is imported by **zero** tests.
- `resources/js/pages/NurseStation/Show.tsx` (471 lines) is imported by **zero** tests.
- `resources/js/features/front-desk/components/FrontDeskBoard.tsx` has a test; `CheckInAction.tsx` does not.
- Playwright has 9 tests across 5 files:
  - `tests/Browser/auth.spec.ts` (3)
  - `tests/Browser/health.spec.ts` (2)
  - `tests/Browser/reception-v2.spec.ts` (2)
  - `tests/Browser/patient-registration.spec.ts` (1)
  - `tests/Browser/patient-banner-visual.spec.ts` (1, screenshot only)
- No E2E covers: dispense → pickup → billing clearance, record/allocate payment, triage → handoff, note → disposition → complete.
- No coverage configuration exists (`grep -n coverage vitest.config.ts package.json` → 0).

**Impact**

The flows with the highest cost of regression are the least protected. The suite's 554 passing tests create a false sense of safety for exactly the screens where a defect harms a patient or a cash drawer.

**Remediation**

- Add page tests for both clinical screens (see §10 for the exact case list).
- Add three Playwright journeys (see §10).
- Introduce `coverage` with thresholds scoped to `features/clinical`, `features/pharmacy`, `features/front-desk`, `lib/formatting`, `lib/http` — not a global number that encourages low-value tests.

**Acceptance criteria**

- Clinician and Nurse Station each have ≥ 8 meaningful assertions covering happy path, server-error path, and permission-hidden state.
- The three E2E journeys run in `composer conform:e2e`.
- Coverage thresholds fail CI if the clinical modules regress.

**Effort:** 5–8 days.

---

### MEDIUM

#### FR-07 — No React error boundary

**Severity:** MEDIUM · **Area:** Resilience · **Confidence:** High

**Evidence:** `resources/js/app.tsx:27-38` mounts `CarbonThemeProvider → App` with no boundary. Inertia provides none. Any render throw (bad prop shape, null dereference on a new field) unmounts the tree to a blank page.

**Impact:** one malformed prop blanks the workspace and discards every in-progress `useForm`. The user has no recovery path except reload, which loses the same data.

**Remediation:** add a route-level `ErrorBoundary` around `<App />` (and optionally per page via the resolver) that renders a Carbon `InlineNotification` with the correlation ID from shared props, plus "Reload" and "Go to dashboard". Log to the same sink as `bootstrap.ts:14`.

**Acceptance criteria:** a deliberately thrown child renders the fallback, not a blank screen; the correlation ID is visible and copyable.

**Effort:** 0.5 day.

---

#### FR-08 — Nested `<main>` landmarks

**Severity:** MEDIUM · **Area:** Accessibility / Semantics · **Confidence:** High

**Evidence**

- Carbon's `Content` defaults to `tagName="main"` (verified in `node_modules/@carbon/react/lib/components/UIShell/Content.js`).
- `resources/js/layouts/AppLayout/AppLayout.tsx:102` renders `<Content id="main-content">`.
- Pages then render their own `<main>`:
  - `features/patients/components/PatientChart.tsx:613`
  - `features/hr/components/StaffProfile.tsx:323`
  - `pages/Billing/Index.tsx:158`
  - plus 15 other files (`grep -rln "<main" resources/js`).
- The skip link targets `#main-content`, which still works, but landmark navigation now sees two `main` regions — invalid HTML and confusing for screen readers.

**Impact:** screen-reader users cannot rely on "main landmark" navigation; automated a11y scans will fail; the most-used clinical page is affected.

**Remediation:** keep exactly one `<main>`. Either set `tagName="div"` on `Content` and keep page-level `<main>`, or drop the inner `<main>` and use `<div className="patient-chart__main">`. Prefer the former: it preserves the skip-link target and page semantics.

**Acceptance criteria:** `document.querySelectorAll('main').length === 1` on Patient Chart, Staff Profile, Billing, and HR pages.

**Effort:** 1 day + regression test.

---

#### FR-09 — Docked panels claim dialog semantics without dialog behaviour

**Severity:** MEDIUM · **Area:** Accessibility / Keyboard · **Confidence:** High

**Evidence**

- `resources/js/components/panels/DockedPanel.tsx:111-114`:
  ```tsx
  role="dialog"
  aria-modal="false"
  aria-label={ariaLabel}
  aria-hidden={hidden}
  ```
- Escape is bound unconditionally for the component's lifetime (`:92-99`), including while `hidden` (`:114`), and re-bound whenever `onClose` changes identity. `SuppliersSection.tsx:350,498` keeps two panels mounted hidden, so both listeners stay live.
- No focus is moved into the panel on open, none is trapped, none is restored on close. `grep -rn "\.focus()" resources/js` finds only a test. No `autoFocus` exists anywhere.
- The panel is used by 53 call sites, 47 of them submit forms.
- The project's own Rule 09 says: *"modals keep Carbon's focus trap — do not break it with custom portals."*

**Impact:** keyboard users open a panel and remain focused on the trigger behind it; tab order walks the page before reaching the panel; Escape can close a hidden panel's parent state. Screen readers announce a dialog immediately but focus stays outside it.

**Remediation**

1. Focus the panel heading (or first field) on open; store the trigger element and restore focus on close.
2. Trap Tab within the panel only if you decide it is modal; for a non-modal side panel, at minimum move focus in and close on Escape, and label the region with `aria-label` (already present).
3. Bind the Escape listener only when `!hidden && open`, not for the component lifetime.
4. Mark the hidden state with `inert` (or ensure `display:none`, which `style.display` already does) so hidden panel controls are not focusable.

**Acceptance criteria:** opening a panel moves focus inside; closing returns it to the trigger; Escape does nothing when no panel is visible; keyboard-only completion of "Add supplier" works.

**Effort:** 1–2 days + keyboard test.

---

#### FR-10 — Timestamps use three incompatible conventions

**Severity:** MEDIUM · **Area:** Clinical correctness / Consistency · **Confidence:** High

**Evidence**

| Convention | Where | Count |
|---|---|---|
| UTC, explicitly labelled | `lib/formatting/dates.ts:55-66` via `formatClinicalDateTime` | 4 files (PatientChart, PatientBanner, PatientStickyNote, StaffBanner) |
| Browser-local `Intl` / `toLocale*` | Clinician, Nurse Station, Appointments, Pharmacy, Inventory, Billing, Procurement, Audit | **23 files** |
| Facility zone from backend `timezone` prop | `lib/formatting/facility-time.ts`, used by Front Desk and Dashboard | 1 workspace |

- The `timezone` prop is only supplied by Reception and Dashboard (`app/Domains/Encounter/Presentation/Http/Controller/ReceptionController.php:57,251`; `app/Http/Controllers/DashboardController.php:71`). It is **not** shared globally in `HandleInertiaRequests`.
- The inconsistency is internal to one feature:
  - `AppointmentDayAgenda.tsx:20-24` converts the instant through the browser zone.
  - `BookAppointmentPanel.tsx:47-49` extracts `HH:MM` text and never converts:
    ```ts
    function localTime(value: string): string {
      return /(?:T|\s)(\d{2}:\d{2})/.exec(value)?.[1] ?? '';
    }
    ```
- `BookAppointmentPanel.tsx:123` defaults the booking date to `localDate(new Date())` — the browser's today, not the facility's.
- `PatientChart.tsx:547-548` and `StaffProfile.tsx:300-301` compute "today"/"this month" from the device clock.

**Impact:** staff working across time zones (telehealth, regional admin, travelling clinicians) see different times on adjacent screens, and a slot chosen on one screen can be stored shifted. Within a single-zone deployment the visible risk is low, but the inconsistency guarantees future defects and makes audit reconstruction ambiguous.

**Remediation**

1. Add `timezone` to `HandleInertiaRequests::share()` (single source: `config('app.facility_timezone')`).
2. Delete browser-local date formatting from clinical workspaces; route everything through `formatDateTimeInZone` / `formatTimeInZone` / `dayInZone`.
3. Make `localTime` a formatting function over the zone, not a regex over the string.
4. Add a test that renders with `TZ=America/New_York` and asserts displayed times match the facility zone.

**Acceptance criteria:** appointment, encounter, pharmacy, and inventory timestamps are identical for two users in different browser zones; booking a 09:00 slot stores 09:00 facility time regardless of device zone.

**Effort:** 2–3 days.

---

#### FR-11 — The form contract is violated by the shared shell and most native forms

**Severity:** MEDIUM · **Area:** Forms & validation · **Confidence:** High

**Evidence**

- Rule 06: *"Every `<Form>` carries `noValidate`."*
- Actual counts:
  - 23 Inertia `<Form>` elements, **15** with `noValidate`, **8** without.
  - 12 other native `<form>` elements, **0** with `noValidate`.
  - 1 `DockedPanel` `<form>` (`DockedPanel.tsx:143`) serving **47** submit panels — no `noValidate`.
- Net: 21 of 36 explicit form elements (58%) lack it, and the one shared panel form covers 47 additional submit flows.
- Error surfacing is also uneven: **29 of 47** submit panels contain no `<FormErrors>`.
  - Some pages compensate globally: `InventoryWorkspace.tsx:329`, `HR/Staff/Show.tsx:38` (`HRWorkflowFeedback`).
  - Others do not: the procurement cancel panel (`RequestsSection.tsx:995-1030`) and several HR panels (`StaffProfile.tsx:634,651,672,691,723,834`) rely on field-level errors only.
- Good counter-evidence: 249 `invalid={…}` props across 213 `TextInput` usages and 42 `FormErrors` instances — the wiring exists where it was remembered.

**Impact:** native bubbles appear in the browser's language, ignore Carbon styling, can block submission of values the server would accept, and are invisible to the form-level error region. Pseudo-field errors (`lifecycle`, `cash_session`, `receipt`) can be silent on some panels.

**Remediation**

1. Add `noValidate` to `DockedPanel.tsx:143`. This fixes 47 panels in one line.
2. Sweep the 12 native forms; add `noValidate` and convert the financial ones to `useForm` first (FR-04).
3. Add an ESLint rule or a structure test asserting every `<form>` / `<Form>` in `resources/js` has `noValidate`.
4. Require `<FormErrors>` in every submit panel; where a page renders shared errors globally, add a comment and a test.

**Acceptance criteria:** a repository test fails if any form lacks `noValidate`; every submit panel renders a form-level error region; no native validation bubble appears in manual testing.

**Effort:** 1–2 days + guard test.

---

#### FR-12 — Responsive coverage is thin

**Severity:** MEDIUM · **Area:** Responsive design · **Confidence:** Medium (no device testing performed)

**Evidence**

- 37 media queries across 6,832 CSS lines: 30 `max-width`, 3 `min-width`, 3 `prefers-reduced-motion`, 1 `any-hover`.
- Distribution is roughly two breakpoints per workspace stylesheet.
- The shell assumes a wide viewport: `useMediaQuery('(min-width: 66rem)')` controls the persistent side nav (`AppLayout.tsx:66`), and workspace grids use fixed columns (`hr-staff.css:189` `minmax(0, 1fr) 15rem`, `nurse-station.css:24` `repeat(4, …)`).
- The clinician layout is inline-styled with `minmax(min(100%, 380px), 1fr)` (`Clinician/Show.tsx:201`), which helps, but the surrounding shell does not.

**Impact:** bedside tablet use is a primary clinical scenario; the current CSS was designed for desktop. Without device testing the exact breakage is unconfirmed, hence Medium confidence.

**Remediation:** define two breakpoints in `tokens.css`, audit the six clinical workspaces at 768 px and 1024 px, then fix the shell (collapsible nav, stacked panels) before per-page fixes.

**Acceptance criteria:** at 768×1024, Clinician, Nurse Station, Patient Chart, Front Desk, Pharmacy, and Appointments are usable without horizontal scrolling; all primary actions remain reachable.

**Effort:** 5–8 days (audit + fixes).

---

#### FR-13 — Inline styles bypass the design system and theming

**Severity:** MEDIUM · **Area:** Design system · **Confidence:** High

**Evidence**

- 406 `style={{…}}` occurrences across 56 files.
- Highest concentrations are clinical/admin pages: `Clinician/Show.tsx:201-206,238,295,480-481`, `StaffProfile.tsx:391,443,464-467,489,518-539`, `NurseStation/Show.tsx:321,358-360,382`.
- Inline styles cannot consume `--afy-*`/`--cds-*` theme tokens, so g90/g100 themes and future density modes cannot apply.
- `tokens.css` itself contains one raw value, `--afy-hairline: rgb(0 0 0 / 8%)`, contrary to its own stated rule.

**Impact:** theme drift, inconsistent spacing, and a second styling system that new contributors will copy.

**Remediation:** add an ESLint `no-restricted-syntax` rule for `JSXAttribute[name.name="style"]` with a small allowlist (dynamic geometry only, e.g. panel offsets and computed positions). Migrate the clinical pages first; they carry the most inline styles and the most risk.

**Acceptance criteria:** inline style count < 50, all dynamic; clinician and nurse pages use foundation classes only.

**Effort:** 3–5 days.

---

#### FR-14 — Billing and parts of Admin remain legacy-quality pages inside a modern shell

**Severity:** MEDIUM · **Area:** Design system / Maintainability · **Confidence:** High

**Evidence**

- Native forms with inline style constants (`Billing/Index.tsx:425-427`); invoices, charges, and payments are rendered as unstructured `<ul>` lists (`:262-301,344-353`) rather than a table; raw status strings; no `PageHeader` actions; no `EmptyState`, no `ErrorState`.
- Raw ISO/status output: invoice status rendered as `invoice.status` (`:285`), payment method as `payment.method` (`:348`).
- `formatMoney` uses locale-dependent `Number.toLocaleString` with a hardcoded `TZS` fallback (`:415-418`).
- Contrast with the rest of the app: 33 files use the shared `CarbonDataTable`, 44 `EmptyState`, 11 `ErrorState`, 20 skeletons.

**Impact:** the revenue workflow looks and behaves differently from every other workspace, and the divergence will grow until FR-01 is resolved.

**Remediation:** migrate Billing to `PageHeader`, `CarbonDataTable`, `useForm`, `EmptyState`, `ErrorState`, and foundation tokens in the same change that restores navigation.

**Acceptance criteria:** Billing passes the same visual/structural review as Inventory or Procurement; zero inline style constants.

**Effort:** 3–4 days.

---

#### FR-15 — No localization layer

**Severity:** MEDIUM · **Area:** Product / i18n · **Confidence:** High

**Evidence:** no `lang/` or `resources/lang/`, no i18n library, no `__()` or `useTranslation` in the frontend; all copy is hardcoded English; date helpers hardcode `'en'`/`'en-CA'` (`facility-time.ts:21,56`); `config/app.php:96` defaults to `en`.

**Impact:** Swahili-first facilities cannot use the product in their working language; printed artefacts (GRN, requisitions, receipts) inherit English. Retrofitting i18n after more screens ship multiplies the cost.

**Remediation:** decide the localization target now. If Swahili is required, introduce a message catalogue and a `t()` helper before adding more screens, and move date/number formatting behind locale-aware helpers.

**Acceptance criteria:** a documented decision; if yes, one workspace fully localized as the reference implementation.

**Effort:** 2 days (decision + scaffolding), 2–3 weeks (full extraction).

---

#### FR-16 — No accessibility automation

**Severity:** MEDIUM · **Area:** Accessibility · **Confidence:** High

**Evidence:** no `eslint-plugin-jsx-a11y`, no `jest-axe`/`axe-core`, no Playwright a11y scan (`ls node_modules | grep -i axe` → nothing relevant; `package.json` has none). Rule 09 relies entirely on manual review.

**Impact:** the nested-`main` and focus defects (FR-08, FR-09) would have been caught by a single axe pass. Regressions will recur.

**Remediation:** add `eslint-plugin-jsx-a11y` (recommended config), `vitest-axe` for 4–5 critical screens, and one Playwright axe assertion per journey.

**Acceptance criteria:** CI fails on serious/critical axe violations in Login, Patient Chart, Clinician, Front Desk.

**Effort:** 1–2 days.

---

#### FR-17 — Flash toasts never expire

**Severity:** MEDIUM · **Area:** UX / Feedback · **Confidence:** High

**Evidence:** `AppLayout.tsx:11-47` renders `ToastNotification` with no timeout, no dismiss handler, and no stack cap. Carbon's default close button is the only dismissal.

**Impact:** success messages persist across interactions until the next navigation; repeated actions stack and obscure content.

**Remediation:** auto-dismiss success/info after 8 s, keep errors until dismissed, pause timers on hover/focus, cap at 3 visible toasts.

**Acceptance criteria:** a success toast disappears within 10 s; an error toast persists until dismissed; five rapid actions do not produce five stacked toasts.

**Effort:** 0.5 day.

---

#### FR-18 — The HTTP funnel has no timeout or abort

**Severity:** MEDIUM · **Area:** Resilience / Performance · **Confidence:** High

**Evidence:** `lib/http/json.ts:22-40` passes no `AbortSignal` and sets no deadline. Callers guard against races with `cancelled` flags (good) but cannot cancel the underlying request.

**Impact:** a stalled request leaves "Checking stock…", "Loading open purchase-order lines…", or "Searching…" indefinitely, with no retry affordance.

**Remediation:** accept an optional `signal` and default to `AbortSignal.timeout(15_000)`; surface a timeout-specific message with a retry action.

**Acceptance criteria:** a request that never resolves stops showing a spinner after 15 s and offers retry.

**Effort:** 0.5 day + tests.

---

#### FR-19 — Goods receipt loads up to 500 lines in sequential pages

**Severity:** MEDIUM · **Area:** Performance · **Confidence:** High

**Evidence:** `GoodsReceiptWorkbenchPanel.tsx:283-310` awaits page 1, then loops pages 2…`lastPage` serially (`for (let page = 2; page <= first.meta.lastPage; page++)`), accumulating arrays. A 500-line PO at 100 lines/page is five serial round trips under one spinner.

**Impact:** slow receipt entry on large deliveries; no progress indication; the panel's interactions are blocked while loading.

**Remediation:** raise the page size for this endpoint, or fetch pages 2…n with `Promise.all` once `lastPage` is known; show determinate progress if more than two pages.

**Acceptance criteria:** a 500-line PO loads in ≤ 2 round trips or in parallel; a loading indicator reports progress.

**Effort:** 1 day.

---

#### FR-20 — Generated contract types and three dependencies are unused

**Severity:** MEDIUM · **Area:** Maintainability / Contract drift · **Confidence:** High

**Evidence**

- `resources/js/shared/types/generated.ts` is 506 lines, regenerated by `php artisan typescript:transform` and diff-checked in `composer conform` (composer.json `conform` step 3-4), yet no frontend module imports it (`grep -rn "shared/types/generated" resources/js` → 0).
- Each feature hand-writes parallel interfaces (e.g. `features/appointments/types/calendar.ts`, `features/pharmacy/types/pharmacy.ts`).
- `package.json` declares `sonner`, `laravel-echo`, `pusher-js`; none are imported anywhere in `resources/js`.

**Impact:** two descriptions of the same backend contract will drift; unused dependencies add install time and dependency-review surface.

**Remediation:** either import generated DTOs in feature types (preferred) or delete the generation step. Remove unused dependencies or add the realtime layer they imply.

**Acceptance criteria:** one source of truth for DTOs; `npm ls` shows no unused direct dependency.

**Effort:** 2–3 days (adoption) or 0.5 day (removal).

---

#### FR-21 — Patient identifiers travel in URLs

**Severity:** MEDIUM · **Area:** Privacy · **Confidence:** Medium

**Evidence**

- API search: `patientSearchUrl` sets `q` in a GET query (`components/patient-search/patientSearch.ts:44-48`).
- Patient list: `?q=` is applied to the address bar (`PatientSearchBrowse.tsx:48-56`), so names persist in browser history and can be shared by copy-pasting the URL.
- Billing search does the same (`Billing/Index.tsx:109`).
- Front Desk roster search also puts the filter in the URL (`FrontDeskBoard.tsx:58-63`), though that filter is usually a status/date rather than a name.

**Impact:** names and patient numbers appear in server/proxy access logs, browser history, and any URL-sharing workflow. Not a direct breach, but contrary to minimum-necessary handling.

**Remediation:** use POST for identifier searches, or keep `q` client-side and send it as a header/body; if GET is required, redact query strings in the log pipeline.

**Acceptance criteria:** no patient name or number appears in a persisted URL or application access log.

**Effort:** 1–2 days.

---

#### FR-22 — Test signal degrades under load

**Severity:** MEDIUM · **Area:** Testing / Reliability · **Confidence:** High

**Evidence**

- Full run: 1 failed file, 1 failed test at `navigation.test.tsx:654` ("renders primary task as right-side icon buttons…"), timeout at 20 s.
- Two Vitest worker errors: `Timeout calling "onTaskUpdate"`.
- Isolated rerun: **pass in 16.08 s** — far too close to the 20 s ceiling.
- Suite duration: ~163 s wall / 598 s test time.
- The test renders the entire `AppHeader` with `CarbonThemeProvider` and a full permission map; it asserts layout classes and order.

**Impact:** CI failures that are not defects train the team to re-run rather than investigate; the 16 s test also slows every local loop.

**Remediation:** split the header test into (a) navigation data assertions (pure `workspaceNavigation`) and (b) a smaller render assertion; mock `ResizeObserver` more cheaply; consider `pool: 'forks'` or per-file isolation for the heavy suites; keep `testTimeout` at 20 s but ensure no test needs > 8 s.

**Acceptance criteria:** three consecutive full-suite runs are green; no test exceeds 8 s; worker timeout errors disappear.

**Effort:** 1 day.

---

### LOW

| ID | Finding | Evidence | Fix |
|---|---|---|---|
| FR-23 | No formatter configured; cosmetic source glitch | No Prettier config or `format` script; `dates.ts:40` packs `{  if (!value) return null;` onto the function signature line | Add Prettier + `format` script + `format:check` in `conform` |
| FR-24 | Currency hardcoded to TZS | `OrdersSection.tsx:659,1067`; `Admin/ProcurementLimits/Index.tsx:108,160,187-188`; `Billing/Index.tsx:417` fallback; `GoodsReceiptWorkbenchPanel.tsx:176` default | Derive from facility/record `currency`; keep a single formatter |
| FR-25 | Raw ISO dates in HR | `StaffProfile.tsx:351,365,436,481` render `YYYY-MM-DD` while patients use `formatDobDate` | Use the shared formatting helpers |
| FR-26 | Browser "today" in clinical defaults | `PatientChart.tsx:547-548`, `StaffProfile.tsx:300-301`, `BookAppointmentPanel.tsx:123` | Use `dayInZone(timezone)` (depends on FR-10) |
| FR-27 | Closed Carbon modals stay mounted | `ConfirmDialog.tsx:19-32` always renders `<Modal open={open}>`; hidden headings remain in the DOM | Render conditionally, or document the test-query hazard (already noted in two components) |
| FR-28 | ARIA disclosure inconsistency | 26 `aria-expanded`, 8 `aria-controls`; `FrontDeskVisitSummary.tsx:123-156` portals a panel with no role/`aria-controls` and no focus move | Add `aria-controls` + region role; move focus to the panel on open |
| FR-29 | Route drift for hand-built paths | `PatientStickyNote.tsx:24` (`/patient/{id}/sticky-note`), `CheckInAction.tsx:137` (`/workspaces/reception`), `AppointmentLifecycleButtons.tsx:51` (`/workspaces/appointments/calendar`), `navigation.ts:158,334` (`/patient/` prefix) | Add canonical builders to `app/routes.ts` |
| FR-30 | E2E default credentials in source | `tests/Browser/patient-banner-visual.spec.ts:9-10` defaults to `reception@afyanova.local` / `password` | Require env vars in CI; keep defaults only in a seeded demo profile |
| FR-31 | Documentation drift | `app/bootstrap.ts:7` says "No progress-bar dependency" while `app.tsx:36` configures `progress` | Correct the comment |
| FR-32 | `formatMoney` locale-dependent | `Billing/Index.tsx:415-418` uses `toLocaleString(undefined, …)` | Use the shared money formatter with an explicit locale |

### INFORMATIONAL

| ID | Observation |
|---|---|
| FR-33 | React 19 capabilities (`useTransition`, `useOptimistic`, `Suspense`) are unused. Not required; note only. |
| FR-34 | `auth.can` sends 241 booleans (~8 KB) on every response. Acceptable; a diff/bitmask would scale better. |
| FR-35 | No realtime layer; `QueueBoard` polls every 15 s with `router.reload({ only })` and correctly skips hidden tabs (`QueueBoard.tsx:84,767-771`). |
| FR-36 | Build profile: 95 chunks, 2.9 MB total, 1.08 MB CSS (119 KB gzip), largest chunk 320.54 kB / 99 KB gzip. Per-page splitting works; CSS is the shared cost. |
| FR-37 | Only the theme preference is persisted client-side (`afyanova-theme`); no tokens or PHI in `localStorage`. |
| FR-38 | Permission-gated UI is consistent, and the backend remains the authority — as documented in `HandleInertiaRequests`. |

---

## 8. Positive findings

1. **Governance that executes.** `composer conform` chains Pint, PHPStan, generated-type drift, architecture tests, domain tests, conformance, lint, types, and Vitest. Rules 01–12 are enforceable contracts, not aspirations.
2. **Registration draft mechanism.** Debounced encrypted server draft, serialized saves, `sendBeacon` on `pagehide`, explicit status, 7-day expiry, discard flow (`RegisterPatientForm.tsx:273-341`; backend scoping confirmed in `PatientRegistrationController`).
3. **Pharmacy dispensing.** `submitting` guard, decimal-safe quantity summation, per-location/per-batch eligibility, expired batches disabled, availability errors never rendered as zero, allergy alert, server revalidation (`DispensePanel.tsx:30-49,106-142,167-248`).
4. **Inventory idempotency.** Client-generated receipt IDs and idempotency keys on every mutating command (`GoodsReceiptWorkbenchPanel.tsx:166` plus 8 panels).
5. **Race discipline.** Both debounced PO lookups use `cancelled` flags and clear timers (`GoodsReceiptWorkbenchPanel.tsx:204-327`).
6. **Shared UI primitives.** 33 files use one `CarbonDataTable` wrapper; only 5 raw tables remain. 44 `EmptyState`, 11 `ErrorState`, 20 skeletons, 29 `role="status"`, 7 `aria-live`.
7. **Security posture at the boundary.** CSRF meta token on non-GET JSON, `same-origin` credentials, no `dangerouslySetInnerHTML`, no direct `fetch`, no PHI in storage, `rel="noreferrer"` on all new-tab links.
8. **Accessibility basics.** Skip links in both shells (`Header.tsx:85`, `AuthLayout.tsx:22`), labelled controls, `invalidText` wiring, never color-only status.
9. **Build health.** Strict TypeScript, zero-warning lint, 1.78 s production build, real per-page splitting.

---

## 9. Roadmap

### Phase 1 — Safety stopgaps (weeks 1–2)

| Deliverable | Findings | Exit criteria |
|---|---|---|
| Confirm-before-complete + `danger` → `primary` | FR-03 | No POST without confirmation; test asserts it |
| Billing submit guards + idempotency keys | FR-04 | Double-click yields one payment |
| Clinical draft/autosave + navigation blocker | FR-02 | Note survives navigation/reload |
| Allergy strip in encounter header | FR-05 | Allergy status visible to prescriber |
| `noValidate` on `DockedPanel` | FR-11 | 47 panels fixed; guard test added |
| Resolve Billing navigation | FR-01 | Reachable in ≤ 2 clicks, or explicitly removed |

No structural changes in this phase. Every item is independently shippable.

### Phase 2 — Consistency and correctness (weeks 3–6)

| Deliverable | Findings |
|---|---|
| Facility-timezone unification + shared `timezone` prop | FR-10 |
| `DockedPanel` focus management + conditional Escape | FR-09 |
| Root error boundary | FR-07 |
| Single `<main>` landmark | FR-08 |
| HTTP timeout/abort | FR-18 |
| Toast lifecycle | FR-17 |
| Page tests for Clinician and Nurse Station (extract forms while testing) | FR-06 |

Exit criteria: cross-timezone rendering test passes; keyboard-only panel walkthrough passes; clinical page tests exist and are green.

### Phase 3 — Structural debt (months 2–3)

| Deliverable | Findings |
|---|---|
| Split the four >1,000-line files (and `QueueBoard` at 988) along data-hook / section / panel seams | Maintainability |
| Migrate Billing and Admin onto the foundation | FR-14 |
| Replace inline styles in clinical pages | FR-13 |
| Adopt or delete generated DTOs; remove unused deps | FR-20 |
| `jsx-a11y` + axe smoke tests | FR-16 |
| Responsive audit and fixes for six clinical workspaces | FR-12 |
| De-flake the header test; add coverage config | FR-22 |

Exit criteria: no file > 700 lines except documented exceptions; zero serious axe violations on primary flows; tablet layouts usable.

### Phase 4 — Strategic capability (quarter+)

| Deliverable | Findings |
|---|---|
| i18n (Swahili-first catalogue, locale-aware formatting) | FR-15 |
| Allergy/interaction checks at order entry; critical-value flagging | FR-05 |
| Privacy hardening of search (POST/redaction) | FR-21 |
| Realtime decision (adopt Echo/Pusher or formalise polling) | FR-35 |
| Accessibility and performance budgets in CI | FR-16, FR-36 |

Sequencing rationale: Phase 1 removes the failure modes that can hurt a patient or a cash drawer; Phase 2 removes the inconsistencies that generate new defects; Phase 3 pays down structure once tests exist to make it safe; Phase 4 is product scope.

---

## 10. Testing recommendations

### 10.1 New page tests (highest value)

`resources/js/pages/Clinician/Show.test.tsx`

1. Renders encounter context from props (queue ticket, priority, encounter status).
2. Save note: posts expected payload; success resets the textarea.
3. Save note failure: server error renders in `invalidText`; textarea retains text.
4. Diagnosis: required description blocks submit server-side; error displayed.
5. Plan: success resets the five fields.
6. Disposition: changing after save disables "Complete visit" until re-saved.
7. "Complete visit" is disabled until `dispositionSaved`.
8. Permission-hidden sections do not render for a user without `canRecordNote`.
9. Dirty note + panel switch opens `DiscardChangesModal`; "Keep" preserves text.

`resources/js/pages/NurseStation/Show.test.tsx`

1. Vitals inputs disabled unless `IN_SERVICE`.
2. Server range error (e.g. temperature 60) renders per-field error.
3. Save vitals clears the form and shows status.
4. Triage: priority/acuity/consciousness render; required chief complaint enforced server-side.
5. Handoff requires destination queue and reason.
6. Permission-hidden sections.

### 10.2 New E2E journeys (Playwright)

1. `dispense-to-payment.spec.ts` — prescribe → dispense → pickup blocked → cashier records payment → pickup succeeds. Covers FR-01, FR-04, FR-05 seam.
2. `payment-idempotency.spec.ts` — double-click "Record payment"; assert one payment and one receipt.
3. `encounter-completion.spec.ts` — start → note → disposition → complete → assert confirmation gate and final status.

### 10.3 Contract and invariant tests

- `formatting.timezone.test.ts`: render times with `process.env.TZ='America/New_York'`; assert equality with facility-zone expectations.
- `structure.test.ts` addition: every `<form>`/`<Form>` includes `noValidate`.
- `landmarks.test.ts`: render Patient Chart, Staff Profile, Billing; assert exactly one `<main>`.
- `billing.navigation.test.ts`: `workspaceNavigation('/workspaces/billing', can)` includes and activates billing.

### 10.4 Accessibility checks

- `eslint-plugin-jsx-a11y` recommended config.
- `vitest-axe` on Login, Patient Chart, Clinician, Front Desk.
- One Playwright axe scan per E2E journey (serious/critical fail the build).
- Manual keyboard script: open/close every docked panel, complete a supplier form, navigate the chart rail, use the facility switcher.

### 10.5 Suite health

- Split `navigation.test.tsx:654`; no test should need > 8 s.
- Add coverage config with thresholds on `features/clinical`, `features/pharmacy`, `features/front-desk`, `lib/formatting`, `lib/http`.
- Keep `testTimeout: 20000` only as a safety net, not as the expected duration.

**Explicitly avoid:** raising global timeouts to hide the flake, or snapshot-testing large clinical pages.

---

## 11. Final verdict

**1. Is the frontend production-ready for clinical use?**
No — but it is two weeks of focused work away, not a rewrite. Six HIGH defects sit on the exact paths where mistakes are expensive.

**2. Is it safe for patients today?**
Not fully. The specific unsafe edges are allergy invisibility at prescribing, unconfirmed encounter completion, and documentation that evaporates on navigation. Downstream controls (pharmacist attestation, server revalidation, permission checks) prevent the worst outcomes, which is why no finding is CRITICAL — but they are compensating controls, not design.

**3. Is the architecture sound enough to build on?**
Yes. The layering, capability-based permissions, transport discipline, and shared UI primitives are stronger than most codebases at this size. The weaknesses are local: legacy pages, monolith files, an unused generated contract.

**4. Are security and privacy adequate at the frontend boundary?**
Largely yes. CSRF, same-origin fetch, no HTML injection, no PHI in storage, capability gating, strict typing. Residual items are privacy hygiene (identifiers in URLs), an over-broad permission map, and absent a11y/security automation — MEDIUM or lower.

**5. Can the test suite be trusted?**
Partially. 566 test cases with no skipped tests is real coverage, and the architecture/contract tests are unusually good. But the two highest-risk pages have zero tests, medication and payment have no E2E, one test is load-flaky, and coverage is unmeasured. Green means "no known regression in covered areas", not "safe to ship".

**6. Refactor or iterate?**
Iterate, with targeted refactors. Phase 1 is stopgaps; Phase 2 is consistency; Phase 3 is where refactoring belongs, scoped to the four >1,000-line files (plus `QueueBoard` at 988) and the legacy Billing/Admin pages. A rewrite would discard the repository's strongest asset — the enforced architecture — to fix problems that are localized and well understood.

---

## 12. Assumptions and limits

- Line numbers are from the working tree as of 2026-10-23 and will drift.
- No Git metadata was available, so change provenance and "was this intentional?" questions are answered from code comments and tests, not history.
- Billing's navigation omission is treated as a defect because its page, routes, controllers, and tests exist and the code comment says to add the workspace when the slice lands. If rollout is intentionally gated, FR-01's remediation changes from "add the entry" to "remove or explicitly disable the page" — the finding stands either way.
- Backend validation ranges were inspected only to avoid overstating frontend findings; the backend parity claim relies on the project's own contract documents and is not an independent security review.
- No device, screen-reader, or load testing was performed; FR-12 and parts of FR-10 carry Medium confidence for that reason.

---

*Report generated read-only. No application code, dependency, configuration, or data was modified. The production build used for measurement was written to `/tmp/opencode/afyanova-build`.*
