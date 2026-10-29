<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The write-side persistence port for an aggregate root
 * (IMPLEMENTATION-CONTRACTS.md §9). One `{Aggregate}Repository` per aggregate;
 * the interface lives in the domain's `Domain/`, the Eloquent implementation in
 * `Infrastructure/Repository/`, bound in the module service provider. A
 * concrete interface MAY narrow the return type to its own aggregate.
 */
interface Repository
{
    /**
     * The hydrated aggregate, RLS-scoped. Throws `NotFound` (a `DomainException`)
     * when it does not exist in the current tenant scope. Never bypasses the
     * tenant global scope, never a raw connection (§9).
     */
    public function getById(string $id): AggregateRoot;

    /**
     * Like {@see getById()} but `lockForUpdate()` inside the current transaction
     * — for the hot rows §9.1 names (bed assignment, stock deduction, payment
     * posting, slot claim).
     */
    public function getByIdForUpdate(string $id): AggregateRoot;

    /**
     * Persist the aggregate (versioned `UPDATE`/`INSERT`, bumps `version`) AND
     * write its released domain events to the outbox — all in the bus
     * transaction (§5, §7.2).
     */
    public function save(AggregateRoot $aggregate): void;
}
