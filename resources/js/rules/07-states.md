# Rule 07 — Loading, error, empty states

Use the existing primitives — never invent new ones per slice.

| Situation | Primitive |
|---|---|
| First paint, no data yet (non-table region) | `LoadingState` (Carbon `SkeletonText`) |
| First table load, no rows yet | `TableLoadingState` (Carbon `DataTableSkeleton`, row-shaped) |
| Refresh with rows on screen | Keep stale rows mounted + `InlineLoading` pending indicator; disable search/submit/pagination controls (`disabled`, `aria-busy` region). Never swap content for a skeleton. |
| Inertia navigation in progress | Global progress bar (`app.tsx`, Carbon blue) — no per-page spinners for navigation itself |
| Form submitting | Disabled submit button with `…ing` label (`processing`); inline, no overlay |
| Partial reload pending | Regional pending indicator on the refreshing region only (no `only:` consumer yet — first one sets the example) |
| No results | `EmptyState` with the backend's terms, plus the action that resolves it (e.g. link to register) |
| Soft backend failure (`searchError`, `{ error }`, `search.errors`) | `ErrorState` with the backend's message verbatim |
| Transport failure (network/HTTP during a visit) | `ErrorState` + retry by re-issuing the same visit; stale rows are dropped only here (no data to keep) |
| Form-level failure | `FormErrors` (Rule 06) |
| Hard failure (403/404/500) | Branded `errors/*.blade.php` for direct navigation; `Errors/*` pages stay ready for Inertia-driven flows |

Rules: never show loading for instant local interactions; never replace a
whole page for a regional refresh; never write failure copy the backend did
not send; empty states always name the way out.
