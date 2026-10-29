# Rule 02 — Resource-level authorization

Backend policies and resource-level rules are authoritative. The frontend
must never reimplement them.

## What the frontend must never do

- Derive access from resource fields: `record.facilityId === activeFacility`,
  `record.status === 'OPEN'`, `record.createdBy === user.id`, tenant checks,
  ownership checks, or any combination thereof. That is the policy's job
  (`BasePolicy`: permission ∧ tenant ∧ facility scope ∧ domain rule).
- Branch on roles: no `user.role === '…'`, no `roles.includes(…)`, no
  role→permission maps. (Equality/`includes` checks against `.role`/`.roles`
  are banned by `no-restricted-syntax`.)
- Treat a visible button, an enabled tab, or a successful data fetch as proof
  of permission. Presentation is not authorization (Rule 01 covers the global
  `can()` layer; this rule covers per-resource actions).

## What the frontend may do

Consume backend-provided per-resource capabilities when a UI needs to say
whether a *specific* action on a *specific* resource is available — e.g. one
row's "Reverse" button in a list where other rows allow it.

Contract (backend-owned, PHP-serializable):

```ts
resource = {
  id: '…', …domain fields…,
  can: { update: true, delete: false }, // action → allowed, decided server-side
}
```

Read it only through `resourceCan(resource, action)` in
`lib/authorization/resource.ts`. Fail-closed: missing resource, missing `can`
map, or missing action is `false`. Action names are domain-defined strings
supplied by the backend — the frontend must not invent an enum of them.

## Decision procedure

Need per-item action visibility?

1. Ask: does the backend embed `can` on this resource? Yes → `resourceCan`.
2. No → fall back to the coarse global `can()` gate (Rule 01). A whole list
   hidden by `billing.invoices.manage` needs no per-row flags.
3. Never derive the answer from resource fields or roles. If neither source
   answers it, the slice is blocked on the backend — say so explicitly.

## Backend capabilities (corrected)

Page-level capability flags **do** exist and are the established pattern —
flat `canX` booleans computed server-side from the owning permission keys:

- Pharmacy: `canTranscribe` (`pharmacy.prescriptions.accept`),
  `canManageOrders`, `canDispense`, `canReverse`, `canRecordPickup`
  (`PharmacyController:126-130`, keys resolved at `:38-42`).
- Billing cashier: `canManageSessions`, `canManageInvoices`,
  `canRecordPayments` (`CashierController:56-58`).
- Billing catalog: `canManageCatalog`, `canRetryCharges`
  (`CatalogController:64-65`).
- Auth: `canResetPassword` (`AuthenticationServiceProvider:64`).

Consume these directly from page props (`page.props.canDispense`) — no reader
needed; they are already decisions, not data. `resourceCan` remains for the
nested per-row `can` map shape if a presenter ever embeds one (none does
yet). What is still absent: row-level flags and any frontend derivation —
both remain forbidden.
