<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Reader;

use App\Domains\Theatre\Contracts\Dto\AnaesthesiaRecordAmendmentSummary;
use App\Domains\Theatre\Contracts\Dto\AnaesthesiaRecordSummary;
use App\Domains\Theatre\Domain\AnaesthesiaRecordReader;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecordAmendment;
use App\Shared\Domain\NotFound;

final class EloquentAnaesthesiaRecordReader implements AnaesthesiaRecordReader
{
    public function summary(string $anaesthesiaRecordId): AnaesthesiaRecordSummary
    {
        $record = AnaesthesiaRecord::query()->whereKey($anaesthesiaRecordId)->first()
            ?? throw NotFound::aggregate(AnaesthesiaRecord::class, $anaesthesiaRecordId);

        return $this->toSummary($record);
    }

    public function forCase(string $caseId): ?AnaesthesiaRecordSummary
    {
        $record = AnaesthesiaRecord::query()->where('case_id', $caseId)->first();

        return $record !== null ? $this->toSummary($record) : null;
    }

    private function toSummary(AnaesthesiaRecord $record): AnaesthesiaRecordSummary
    {
        $amendments = [];
        $rows = AnaesthesiaRecordAmendment::query()
            ->where('anaesthesia_record_id', $record->id)
            ->orderBy('sequence')
            ->get();

        foreach ($rows as $row) {
            $amendments[] = new AnaesthesiaRecordAmendmentSummary(
                amendmentId: (string) $row->id,
                sequence: (int) $row->sequence,
                amendmentText: (string) $row->amendment_text,
                reason: (string) $row->reason,
                createdByUserId: (string) $row->created_by_user_id,
                createdAt: $row->created_at?->toISOString(),
            );
        }

        return new AnaesthesiaRecordSummary(
            anaesthesiaRecordId: (string) $record->id,
            facilityId: (string) $record->facility_id,
            caseId: (string) $record->case_id,
            patientId: (string) $record->patient_id,
            encounterId: (string) $record->encounter_id,
            anaesthesiaType: (string) $record->anaesthesia_type,
            providerUserId: $record->provider_user_id !== null ? (string) $record->provider_user_id : null,
            startedAt: $record->started_at?->toISOString(),
            endedAt: $record->ended_at?->toISOString(),
            content: $record->content,
            outcome: $record->outcome,
            status: (string) $record->status,
            signedByUserId: $record->signed_by_user_id !== null ? (string) $record->signed_by_user_id : null,
            signedAt: $record->signed_at?->toISOString(),
            createdByUserId: (string) $record->created_by_user_id,
            amendments: $amendments,
            createdAt: $record->created_at?->toISOString(),
            updatedAt: $record->updated_at?->toISOString(),
        );
    }
}
