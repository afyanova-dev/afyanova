<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;
use App\Domains\Clinical\Domain\ClinicalNoteReader;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Shared\Domain\NotFound;

final class EloquentClinicalNoteReader implements ClinicalNoteReader
{
    public function exists(string $clinicalNoteId): bool
    {
        return ClinicalNote::query()->whereKey($clinicalNoteId)->exists();
    }

    public function summary(string $clinicalNoteId): ClinicalNoteSummary
    {
        $note = ClinicalNote::query()->whereKey($clinicalNoteId)->first()
            ?? throw NotFound::aggregate(ClinicalNote::class, $clinicalNoteId);

        return new ClinicalNoteSummary(
            clinicalNoteId: (string) $note->id,
            patientId: (string) $note->patient_id,
            encounterId: (string) $note->encounter_id,
            facilityId: (string) $note->facility_id,
            authorUserId: (string) $note->author_user_id,
            authoredContext: $note->authored_context,
            noteType: (string) $note->note_type,
            title: $note->title,
            body: (string) $note->body,
            status: $note->status->value,
            occurredAt: (string) $note->occurred_at->toISOString(),
            recordedAt: (string) $note->recorded_at->toISOString(),
            signedAt: $note->signed_at?->toISOString(),
            signedByUserId: $note->signed_by_user_id,
            amendmentCount: $note->amendment_count,
        );
    }
}
