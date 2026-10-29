<?php

declare(strict_types=1);

namespace App\Shared\Bus;

use Attribute;

/**
 * Marks a query that exposes PROTECTED data (IMPLEMENTATION-CONTRACTS.md §6.2,
 * §17.5) — patient search, any clinical record, billing figures, insurance
 * data, the audit log, HR records. The QueryBus authorize middleware requires a
 * policy path for it; `afyanova:conformance` fails on a `#[ProtectedQuery]`
 * query with no registered policy.
 *
 * (IC §17.5 calls this `#[Protected]`; `Protected` is a PHP reserved word, so
 * the class is `ProtectedQuery`.)
 *
 * - `ability`      — the policy method (`view`, `list`, or a named ability).
 * - `subjectType`  — the aggregate-root class to route `Gate` to its policy;
 *                    null → the ability is checked at class level.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ProtectedQuery
{
    /** @param  class-string|null  $subjectType */
    public function __construct(
        public string $ability,
        public ?string $subjectType = null,
    ) {}
}
