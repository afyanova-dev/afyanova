<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Reader;

use App\Domains\Theatre\Contracts\Dto\OperativeNoteAmendmentSummary;
use App\Domains\Theatre\Contracts\Dto\OperativeNoteSummary;
use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Domains\Theatre\Domain\Model\OperativeNoteAmendment;
use App\Domains\Theatre\Domain\OperativeNoteReader;
use App\Shared\Domain\NotFound;

final class EloquentOperativeNoteReader implements OperativeNoteReader
{
    public function summary(string $operativeNoteId): OperativeNoteSummary
    {
        $note = OperativeNote::query()->whereKey($operativeNoteId)->first()
            ?? throw NotFound::aggregate(OperativeNote::class, $operativeNoteId);

        return $this->toSummary($note);
    }

    public function forCase(string $caseId): ?OperativeNoteSummary
    {
        $note = OperativeNote::query()->where('case_id', $caseId)->first();

        return $note !== null ? $this->toSummary($note) : null;
    }

    private function toSummary(OperativeNote $note): OperativeNoteSummary
    {
        $amendments = [];
        $rows = OperativeNoteAmendment::query()
            ->where('operative_note_id', $note->id)
            ->orderBy('sequence')
            ->get();

        foreach ($rows as $row) {
            $amendments[] = new OperativeNoteAmendmentSummary(
                amendmentId: (string) $row->id,
                sequence: (int) $row->sequence,
                amendmentText: (string) $row->amendment_text,
                reason: (string) $row->reason,
                createdByUserId: (string) $row->created_by_user_id,
                createdAt: $row->created_at?->toISOString(),
            );
        }

        return new OperativeNoteSummary(
            operativeNoteId: (string) $note->id,
            facilityId: (string) $note->facility_id,
            caseId: (string) $note->case_id,
            patientId: (string) $note->patient_id,
            encounterId: (string) $note->encounter_id,
            content: $note->content,
            status: (string) $note->status,
            reviewedByUserId: $note->reviewed_by_user_id !== null ? (string) $note->reviewed_by_user_id : null,
            reviewedAt: $note->reviewed_at?->toISOString(),
            signedByUserId: $note->signed_by_user_id !== null ? (string) $note->signed_by_user_id : null,
            signedAt: $note->signed_at?->toISOString(),
            createdByUserId: (string) $note->created_by_user_id,
            amendments: $amendments,
            createdAt: $note->created_at?->toISOString(),
            updatedAt: $note->updated_at?->toISOString(),
        );
    }
}
