<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\VitalSignsObservationRepository;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see VitalSignsObservationRepository} (IC §9;
 * ADR-138).
 *
 * `save()` is inherited unchanged for a plain `RecordVitalSigns` (versioned
 * write + drain events to the outbox). `saveCorrection()` persists both rows of
 * a supersession (ADR-138 D7) in the bus transaction: the NEW observation is
 * inserted first (its `supersedes_observation_id` FK points at the existing
 * original), then the original's one-time supersession stamp is written (a
 * versioned UPDATE), then the `VitalSignsObservationCorrected` event is drained.
 *
 * `getById` / `getByIdForUpdate` are overridden only to narrow the return type.
 */
final class EloquentVitalSignsObservationRepository extends EloquentRepository implements VitalSignsObservationRepository
{
    protected function model(): string
    {
        return VitalSignsObservation::class;
    }

    public function getById(string $id): VitalSignsObservation
    {
        return VitalSignsObservation::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(VitalSignsObservation::class, $id);
    }

    public function getByIdForUpdate(string $id): VitalSignsObservation
    {
        return VitalSignsObservation::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(VitalSignsObservation::class, $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        $this->outbox->writeAll($aggregate->releaseEvents());
    }

    public function saveCorrection(VitalSignsObservation $original, VitalSignsObservation $correction): void
    {
        $correction->save();      // INSERT — supersedes_observation_id FK -> $original (exists)
        $original->save();        // versioned UPDATE — superseded_at / superseded_by set once

        $this->outbox->writeAll($correction->releaseEvents()); // VitalSignsObservationCorrected
        $this->outbox->writeAll($original->releaseEvents());   // none
    }
}
