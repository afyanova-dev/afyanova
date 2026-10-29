# Rule 09 — Accessibility

Accessibility is built in, not cleaned up later. The foundation already
provides: `:focus-visible` ring on all interactives, skip links (shell +
auth), `aria-live` flash regions, `role="alert"` error areas, Carbon's
keyboard handling (menus, modals, dropdowns), and label/error wiring via
Carbon inputs. Every slice must preserve and extend this:

- Every control labeled (`labelText`/`titleText` — never placeholder-only);
  every error associated with its field (`invalidText`) plus the form-level
  `role="alert"` (Rule 06).
- Status is never color-only: `Status` always pairs the `Tag` with text.
- Tables get Carbon's semantic table with headers/scopes; modals keep
  Carbon's focus trap — do not break it with custom portals.
- Contrast, touch targets, and motion come from Carbon tokens; the
  `prefers-reduced-motion` and `prefers-color-scheme` defaults are respected,
  never overridden for decoration.
- Tab order follows DOM order; positive `tabindex` is forbidden.
- Verify per slice with keyboard-only walkthrough (Tab/Shift-Tab/Enter/
  Escape) — no separate a11y phase, it ships with the slice or it does not
  ship.
