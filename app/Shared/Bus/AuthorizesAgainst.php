<?php

declare(strict_types=1);

namespace App\Shared\Bus;

/**
 * A command that must be authorized against a specific target aggregate
 * (IMPLEMENTATION-CONTRACTS.md §5.1, §12).
 *
 * The bus authorize middleware resolves `subjectId()` via the owning repository
 * (a read, RLS-scoped) and calls
 * `{Aggregate}Policy::{policyAbility}($actor, $subject, $command)`.
 * `subjectId() === null` → class-level (create) check. A command that does NOT
 * implement this interface is authorized at class level only.
 *
 * DERIVED (§5.1 is silent on how the generic bus discovers "the owning
 * repository"): the bus reflects the command's handler constructor for a
 * parameter typed `…Repository` and derives the aggregate class from that
 * name (`{X}Repository` → `…\Model\{X}` / sibling `{X}`), per the §5 handler
 * skeleton and §2.1 module layout.
 */
interface AuthorizesAgainst
{
    /** The policy method, e.g. `recordPayment` → `InvoicePolicy::recordPayment`. */
    public function policyAbility(): string;

    /** The target aggregate id, or null for a class-level (create) check. */
    public function subjectId(): ?string;
}
