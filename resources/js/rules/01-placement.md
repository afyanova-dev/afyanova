# Rule 01 — Frontend placement

One question decides where code lives. Ask in this order, stop at the first yes.

1. **Is it Carbon functionality? → Use Carbon.** Import from `@carbon/react` /
   `@carbon/icons-react`. Do not wrap, fork, or re-implement a Carbon component
   without a documented reason. Thin app-specific defaults belong in
   `components/ui/` (e.g. `Status` maps domain states onto `Tag`).
2. **Is it a reusable application pattern? → `components/`.** Used by two or more
   pages/domains and carries no domain meaning: page headers, sections,
   empty/loading/error states, navigation chrome, confirmation dialogs.
   `components/` must not import from `features/` or `pages/`.
3. **Does it belong to a specific business domain? → `features/<domain>/`.**
   Patient, scheduling, billing, pharmacy concepts live in exactly one domain
   folder. `features/` must not import from `pages/`; cross-domain imports go
   through backend contracts, not direct component imports.
4. **Is it a page-level composition? → `pages/`.** Pages compose layouts,
   components, and (later) feature slices into the Inertia page the backend
   renders. Page paths must match backend `Inertia::render('<path>')` names —
   never invent page names. Pages may import from anywhere.

## Frozen structure

`resources/js/` top level is frozen. Adding, removing, or renaming an entry
requires updating this rule, `eslint.config.js` boundaries, and
`test/structure.test.ts` in the same change.

| Entry | Responsibility | May import from |
|---|---|---|
| `app.tsx` | Inertia entry only (page resolution, title, progress) | `app/`, `types/` |
| `app/` | Bootstrap, real backend route constants (`routes.ts`), app config | `types/` |
| `layouts/` | `AppLayout` (authenticated shell), `AuthLayout` (guest shell) | `app/`, `components/`, `lib/`, `hooks/`, `types/`, `icons/` |
| `pages/` | One file per backend-rendered Inertia page | anywhere |
| `components/` | `ui/` primitives, `navigation/` chrome, `feedback/` patterns | `app/`, `lib/`, `hooks/`, `types/`, `icons/` |
| `features/` | Per-domain slices (`<domain>/{components,pages,forms,types.ts}`) | `app/`, `components/`, `layouts/`, `lib/`, `hooks/`, `types/`, `icons/` |
| `lib/` | Inertia helpers, formatting, utilities — domain-free | `types/` |
| `hooks/` | Reusable React hooks — domain-free | `lib/`, `types/` |
| `types/` | Shared contracts mirroring backend shared props | nothing |
| `shared/` | `types/generated.ts` — generated mirror of backend shared DTO contracts (config/typescript-transformer.php output). Do not edit by hand. | `nothing` |
| `icons/` | Carbon icon re-exports | nothing |
| `test/` | Vitest setup + architecture tests | `node:*`, `vitest` |
| `rules/` | This file and future numbered rules | nothing |

Global CSS entry is `resources/css/app.css` (Carbon styles + app tokens only).

## Anti-patterns

- New component library or bespoke button/modal/table — use Carbon.
- Domain logic (tariffs, prescriptions, triage states) in `components/` — it rots
  into a junk drawer. Put it in `features/<domain>/`.
- Fetching domain state with axios/fetch/React Query — Inertia is the transport
  (already enforced by `no-restricted-imports`).
- A generic `common/` folder — name it by what it is (`ui/`, `feedback/`,
  `lib/`). `shared/` is reserved for the generated backend-type mirror
  (`shared/types/generated.ts`) plus its doc entry above.
