<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\TriageAssessmentRepository;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see TriageAssessmentRepository} (IC §9; ADR-145).
 *
 * `save()` is inherited unchanged for a plain `RecordTriageAssessment` (versioned
 * write + drain events to the outbox). `saveCorrection()` persists both rows of
 * a supersession (ADR-145 D-F) in the bus transaction: the NEW assessment is
 * inserted first (its `supersedes_triage_assessment_id` self-FK points at the
 * existing original), then the original's one-time supersession stamp is written
 * (a versioned UPDATE), then the `TriageAssessmentCorrected` event is drained.
 *
 * `getById` / `getByIdForUpdate` are overridden only to narrow the return type.
 */
final class EloquentTriageAssessmentRepository extends EloquentRepository implements TriageAssessmentRepository
{
    protected function model(): string
    {
        return TriageAssessment::class;
    }

    public function getById(string $id): TriageAssessment
    {
        return TriageAssessment::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(TriageAssessment::class, $id);
    }

    public function getByIdForUpdate(string $id): TriageAssessment
    {
        return TriageAssessment::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(TriageAssessment::class, $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        $this->outbox->writeAll($aggregate->releaseEvents());
    }

    public function saveCorrection(TriageAssessment $original, TriageAssessment $correction): void
    {
        $correction->save();      // INSERT — supersedes_triage_assessment_id self-FK -> $original (exists)
        $original->save();        // versioned UPDATE — superseded_at / superseded_by set once

        $this->outbox->writeAll($correction->releaseEvents()); // TriageAssessmentCorrected
        $this->outbox->writeAll($original->releaseEvents());   // none
    }
}
