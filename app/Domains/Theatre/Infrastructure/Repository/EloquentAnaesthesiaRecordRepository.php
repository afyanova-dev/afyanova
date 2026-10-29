<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Repository;

use App\Domains\Theatre\Domain\AnaesthesiaRecordRepository;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecordAmendment;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of AnaesthesiaRecordRepository (ADR-155 D-B).
 */
final class EloquentAnaesthesiaRecordRepository extends EloquentRepository implements AnaesthesiaRecordRepository
{
    protected function model(): string
    {
        return AnaesthesiaRecord::class;
    }

    public function getById(string $id): AnaesthesiaRecord
    {
        return AnaesthesiaRecord::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(AnaesthesiaRecord::class, $id);
    }

    public function getByIdForUpdate(string $id): AnaesthesiaRecord
    {
        return AnaesthesiaRecord::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(AnaesthesiaRecord::class, $id);
    }

    public function nextAmendmentSequence(string $anaesthesiaRecordId): int
    {
        $max = AnaesthesiaRecordAmendment::query()
            ->where('anaesthesia_record_id', $anaesthesiaRecordId)
            ->max('sequence');

        return ((int) $max) + 1;
    }
}
