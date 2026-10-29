# features/

Domain-specific implementation lives here. Route-level Inertia pages remain
under `resources/js/pages/`, where `app.tsx` resolves backend component names;
do not add a second `pages/` layer inside a feature.

```
features/<domain>/
  components/
  hooks/      # only when the domain has reusable stateful logic
  lib/        # only for domain helpers no other layer should own
  forms/      # domain forms (useForm stays the mechanism)
  types/      # prop and DTO mirror types, e.g. PatientSearchBrowseProps.ts
  index.ts
```

Keep route pages thin and compose domain components/hooks from
`features/<domain>/`. Patient Search & Browse is the first implemented
feature; its route page is `pages/Patients/Index.tsx` and its UI lives in
`features/patients/components/`. Add future feature code only after checking
the domain's routes, controllers, and DTOs.

The HR staff workspace follows the same boundary: route-level screens live in
`pages/HR/Staff/`, and reusable staff directory, profile, onboarding,
assignment-review, and workforce-setup UI lives in `features/hr/`. Keep those
workflows on their dedicated screens instead of appending them all to the
directory page.
