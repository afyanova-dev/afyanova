# Rule 08 — Notifications & feedback

One transport, one visual language.

- **Server outcomes** (saved, failed, registered, revoked) arrive as flash
  (`status`/`error` + domain keys) and render once as dismissible Carbon
  `ToastNotification` overlays in the layouts, keyed by `flash.id`. The app
  shell owns placement so outcomes never shift page or patient-chart content;
  pages and slices never re-render flash.
- **Confirmations** for destructive/irreversible actions use `ConfirmDialog`
  (Carbon `Modal`): heading, plain-language consequence, explicit confirm
  label, `danger` for destructive. Reference implementation: session revoke
  on `Auth/Sessions`.
- **No second channel.** `sonner` is installed but not adopted. Carbon toasts
  are only the visual presentation of the existing flash transport; do not add
  a separate client-side notification system.
- **Copy** comes from the backend message. Flash toast titles show a short
  first sentence (up to 72 characters); the original message remains available
  under “More details” when longer. Keep backend notifications concise and
  actionable; do not hide relevant next steps. Correlation IDs (`500.tsx`) are
  shown for support, never hidden behind generic copy.
