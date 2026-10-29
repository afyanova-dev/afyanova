<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\DiagnosisRepository;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see DiagnosisRepository} (IC §9; ADR-139).
 *
 * `save()` is inherited unchanged for a plain `RecordDiagnosis` (versioned
 * write + drain events to the outbox). `saveCorrection()` persists both rows of
 * a supersession (ADR-139 D4) in the bus transaction: the NEW diagnosis is
 * inserted first (its `supersedes_diagnosis_id` FK points at the existing
 * original), then the original's one-time supersession stamp is written (a
 * versioned UPDATE), then the `DiagnosisCorrected` event is drained.
 *
 * `getById` / `getByIdForUpdate` are overridden only to narrow the return type.
 */
final class EloquentDiagnosisRepository extends EloquentRepository implements DiagnosisRepository
{
    protected function model(): string
    {
        return Diagnosis::class;
    }

    public function getById(string $id): Diagnosis
    {
        return Diagnosis::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Diagnosis::class, $id);
    }

    public function getByIdForUpdate(string $id): Diagnosis
    {
        return Diagnosis::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Diagnosis::class, $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        $this->outbox->writeAll($aggregate->releaseEvents());
    }

    public function saveCorrection(Diagnosis $original, Diagnosis $correction): void
    {
        $correction->save();      // INSERT — supersedes_diagnosis_id FK -> $original (exists)
        $original->save();        // versioned UPDATE — superseded_at / superseded_by set once

        $this->outbox->writeAll($correction->releaseEvents()); // DiagnosisCorrected
        $this->outbox->writeAll($original->releaseEvents());   // none
    }
}
