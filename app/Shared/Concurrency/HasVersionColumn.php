<?php

declare(strict_types=1);

namespace App\Shared\Concurrency;

use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Builder;

/**
 * Optimistic concurrency control for aggregate roots
 * (IMPLEMENTATION-CONTRACTS.md §9.1):
 *
 *   getById()  → loads version = N
 *   save()     → UPDATE … SET version = N+1, … WHERE id = ? AND version = N
 *                0 rows affected → throw ConcurrencyConflict (→ HTTP 409)
 *
 * `version` starts at 0 (set on `creating`); {@see AggregateRoot}
 * also seeds the attribute so an unsaved aggregate reads 0.
 */
trait HasVersionColumn
{
    public static function bootHasVersionColumn(): void
    {
        static::creating(static function ($model): void {
            if ($model->getAttribute('version') === null) {
                $model->setAttribute('version', 0);
            }
        });
    }

    /**
     * Mirrors Eloquent's `Model::performUpdate` (fresh query, timestamps, dirty,
     * key predicate) and adds the optimistic-lock version predicate + the
     * zero-rows conflict check. The `setKeysForSaveQuery($query)` call is what
     * scopes the UPDATE to THIS aggregate's primary key.
     */
    protected function performUpdate(Builder $query): bool
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $originalVersion = (int) $this->getOriginal('version', 0);
        $this->setAttribute('version', $originalVersion + 1);

        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            $affected = $this->setKeysForSaveQuery($query)
                ->where('version', $originalVersion)
                ->update($dirty);

            if ($affected === 0) {
                throw ConcurrencyConflict::on(static::class, (string) $this->getKey());
            }

            $this->syncChanges();
            $this->fireModelEvent('updated', false);
        }

        return true;
    }
}
