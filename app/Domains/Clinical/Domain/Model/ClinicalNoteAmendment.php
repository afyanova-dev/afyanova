<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Database\Factories\ClinicalNoteAmendmentFactory;
use App\Domains\Clinical\Domain\Enum\ClinicalNoteAmendmentType;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One immutable correction row against a SIGNED {@see ClinicalNote}
 * (12-clinical-domain.md §14, §15, Invariant 6; ADR-134).
 *
 * Its OWN small tenant-scoped aggregate — the same pattern as
 * `PatientIdentityHistory` (ADR-128). The `ClinicalNoteAmended` domain event is
 * recorded by the parent `ClinicalNote`, not here — this record carries no
 * events. It is persisted alongside the note by
 * `EloquentClinicalNoteRepository::save()`.
 *
 * The row is written ONCE and NEVER updated or deleted — `clin_note_amendments`
 * is append-only at the database (`Rls::enableAppendOnly`), so the `version`
 * column stays 0 for the row's whole life (it exists only to satisfy the
 * aggregate table shape, IC §9.1 / §10).
 *
 * @domain      Clinical
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required — copied from the parent note's facility context
 *              (ADR-132, ADR-135); never client input (Invariant 12).
 *
 * @property ClinicalNoteAmendmentType $amendment_type
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 */
final class ClinicalNoteAmendment extends AggregateRoot
{
    /** @use HasFactory<ClinicalNoteAmendmentFactory> */
    use HasFactory;

    protected $table = 'clin_note_amendments';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'clinical_note_id', 'patient_id', 'encounter_id',
        'amendment_type', 'body', 'reason',
        'author_user_id', 'occurred_at', 'recorded_at',
    ];

    protected static function newFactory(): ClinicalNoteAmendmentFactory
    {
        return ClinicalNoteAmendmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amendment_type' => ClinicalNoteAmendmentType::class,
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Build a new amendment row for a signed note (ADR-134). The caller
     * ({@see ClinicalNote::amend()}) has already validated `SIGNED` status via
     * `ClinicalNoteLifecycle`. `$reason` is REQUIRED clinical data and lives on
     * this row (never duplicated to the audit entry). `$body` is Clinical-owned
     * content.
     */
    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $clinicalNoteId,
        string $patientId,
        string $encounterId,
        ClinicalNoteAmendmentType $type,
        string $body,
        string $reason,
        string $authorUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'clinical_note_id' => $clinicalNoteId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'amendment_type' => $type,
            'body' => $body,
            'reason' => $reason,
            'author_user_id' => $authorUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }
}
