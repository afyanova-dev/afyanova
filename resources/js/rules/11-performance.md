# Rule 11 — Performance

No premature optimization. Correctness and architecture first; numbers when
there is something to measure.

- **Stance**: no caching layers, no memoization wrappers, no virtualization,
  no code-splitting beyond what exists — until a measured problem. `React.memo`,
  `useMemo`, and virtual lists are fixes with profiling evidence, not habits.
- **Existing**: no custom chunk splits — the previous `vendor-icons`/
  `vendor-radix` splits were removed with the unused lucide/radix
  dependencies. Revisit code-splitting when the entry JS chunk exceeds
  1 MB (2026-10-02 baseline: ~600 KB single eager chunk) or total CSS
  exceeds 1.5 MB (baseline after Carbon dedup: ~1 MB).
- **Server-side scale is the backend's**: large datasets stay paginated
  server-side (Rule 05); the frontend never holds thousands of records to
  "save" requests.
- **Budgets**: set when the first feature slice lands — record production
  bundle size then, and revisit only on regression with a named cause. This
  rule stays a stance, not a number, until that measurement exists.
  Concrete trigger: revisit code-splitting when the entry JS chunk exceeds
  1 MB (2026-10-02 baseline: ~600 KB single eager chunk) or total CSS
  exceeds 1.5 MB (baseline after Carbon dedup: ~1 MB).
- **Hygiene that costs nothing**: debounce typeahead (done), preserve
  state/scroll on list visits (done), `only:` reloads where regional
  (Rule 03) — all already established, none speculative.
