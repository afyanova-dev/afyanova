# Rule 07 — Authentication

Covers the authentication-track spec (called "Rule 02" there). Numbered 07
here to keep `rules/` sequential; "Rule 02" below always means
`02-resource-authorization.md`.

Laravel (Fortify, session driver) is the authentication authority. React
renders the flows; it owns no session, no token, no security decision.

## Mechanism (all verified)

- Fortify headless, guard `web`, username `email` (lowercased), registration
  disabled. Session driver `database` (120-min idle slide), cookie
  `slug(APP_NAME)-session`, `http_only`, `SameSite=lax`.
- Absolute cap: `EnsureAbsoluteSessionLifetime` — 720 min after
  `authenticated_at`, logout + invalidate + redirect `login` with `status`
  flash `auth.session_expired` (rendered by `AuthLayout`).
- Throttles: `login` 5/min per email+IP, `two-factor` 5/min per login id.
  Lockout/audit events recorded server-side (`AuthEventRecorder`).
- Credential check: email lookup + `Hash::check` + active-status gate, one
  generic `auth.failed` message on the `email` field (no enumeration —
  backend-tested).
- Password rules: `Password::default()` + `confirmed`
  (`PasswordValidationRules`); reset/update actions in Fortify classes, not
  in React. Password-update validation uses the **`updatePassword` error
  bag** (Rule 06 reader applies).
- 2FA: TOTP challenge (`code`) with recovery-code fallback
  (`recovery_code`), confirm-on-enable, recovery-code replacement audited.
- Verification: `MustVerifyEmail` + `verified` middleware on app routes.
  **Gap:** `email_verified_at` is NOT in shared props — the frontend cannot
  tell verified from unverified and must rely on backend redirects.
- Confirmation: `password.confirm` routes, timeout 10800s. No frontend
  DL: nothing in React decides when confirmation is required.
- Logout: `POST /logout` → session invalidated server-side → `/login`.

## Routes & redirects (authoritative, do not hardcode alternatives)

Guest: `/login`, `/forgot-password`, `/reset-password/{token}`.
Intermediate: `/two-factor-challenge`, `/email/verify`,
`/user/confirm-password`. App: `/dashboard` (backend role-redirects),
`/settings/sessions`, everything under middleware `web,auth,verified`.
Successful password or two-factor login always clears any stale intended URL
and redirects to `/dashboard` (Fortify `home`); the backend decides further.

## Shared contract (`HandleInertiaRequests`)

`auth: { user{id,name,email,roles} | null, roles[], can{}, facilities[],
activeFacility }`, `flash{id,status,error,…}`, `correlationId`. Small by
design — no profile data, no tokens, no verified flag (see gap).

## Frontend responsibilities (and only these)

- Submit exactly the backend's fields (`email/password/remember`,
  `code|recovery_code`, `token/email/password/password_confirmation`) via
  Inertia `useForm`/router — locked by `auth-contracts.test.tsx`.
- Render states: `AuthLayout` + Carbon inputs, `processing` buttons,
  server errors (field + `FormErrors`), flash, keyboard flow. Auth pages use
  Carbon's single-column login pattern; 2FA challenge offers an explicit
  authenticator/recovery-code choice, and setup groups enrollment, backup
  codes, and confirmation into clear steps.
- Facility switch posts `facility_id` and trusts the re-rendered context.
- Never: token storage (none exists — only the `afyanova-theme` UI pref in
  `localStorage`), session timers, role-based auth branches, `setUser(null)`
  logout, logging credentials (DEV console.warn logs error shapes only).

## AuthN vs AuthZ boundary

`auth.user == null` answers authentication; `can()` answers authorization —
tested separately. Frontend visibility is never security: every protected
route/action re-checks server-side (`auth`/`verified` middleware,
`abort_unless($user->can(…))`, FormRequest `authorize()`).

## Verification status

- Backend: `tests/Feature/Security/AuthenticationTest.php` — 8 passed,
  27 assertions (pgsql test DB).
- Frontend: `pages/Auth/auth-contracts.test.tsx` — 6 tests pinning field
  names and endpoints for all Fortify forms.
- E2E (`tests/Browser/auth.spec.ts`) exists but was not run here (needs a
  served app + seeded DB); required before release, not for this rule.
