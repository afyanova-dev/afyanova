# Arch rule counter-examples

Each file here is a **deliberate violation** of one architecture rule in
`IMPLEMENTATION-CONTRACTS.md` §17.3 / `tests/Arch/BoundariesTest.php`.

They are `->ignoring('Tests\Arch\Fixtures')`-excluded from the live rules.

Per §17.3, as each real module lands, the rule it would break gets a matching
fixture here plus a meta-test asserting the rule fails on it — "a rule that
cannot be made to fail on a real violation is not a rule."

Current fixtures: none yet (no modules). Added in PHASE 5+.
