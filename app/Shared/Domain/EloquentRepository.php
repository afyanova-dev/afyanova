<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Events\OutboxWriter;

/**
 * Base Eloquent implementation of {@see Repository} (IMPLEMENTATION-CONTRACTS.md
 * §9, §7.2). A concrete `Infrastructure/Repository/Eloquent{X}Repository` names
 * its aggregate class in `model()`; this class provides the RLS-scoped
 * `getById` / `getByIdForUpdate` and the `save` that persists the versioned
 * change **and** drains released events to the outbox — one transaction (the
 * bus's).
 */
abstract class EloquentRepository implements Repository
{
    public function __construct(protected readonly OutboxWriter $outbox) {}

    /**
     * The aggregate-root class this repository owns.
     *
     * @return class-string<AggregateRoot>
     */
    abstract protected function model(): string;

    public function getById(string $id): AggregateRoot
    {
        return $this->model()::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate($this->model(), $id);
    }

    public function getByIdForUpdate(string $id): AggregateRoot
    {
        return $this->model()::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate($this->model(), $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        $this->outbox->writeAll($aggregate->releaseEvents());
    }
}
