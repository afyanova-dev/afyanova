<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Domain\ValueObject\ProcedureAmendmentType;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;

/**
 * One append-only correction row against a completed / aborted
 * {@see ClinicalProcedure} (13-clinical-procedures-domain.md §81, §139; ADR-137
 * — the ADR-134 append-only model; ADR-146 D-M).
 *
 * Its OWN small tenant-scoped aggregate — the `ClinicalNoteAmendment` /
 * `ProblemHistoryEntry` pattern. The `changed_fields` list records WHICH
 * `clin_procedures` columns this amendment touched (outcome, site, etc.) — the
 * corrected values are applied onto the parent row by
 * `ClinicalProcedure::amend()` in the same transaction; this row is the
 * permanent narrative + audit trail of that change, never itself mutated.
 *
 * The row is written ONCE and NEVER updated or deleted (`Rls::enableAppendOnly`);
 * `version` stays 0 for its whole life (IC §9.1 / §10 table-shape only).
 *
 * @domain      Clinical
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required — copied from the parent procedure (ADR-132, ADR-135)
 *
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property array<int, string>|null $changed_fields
 */
final class ProcedureAmendment extends AggregateRoot
{
    protected $table = 'clin_procedure_amendments';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'procedure_id', 'patient_id', 'encounter_id',
        'amendment_type', 'body', 'reason', 'changed_fields',
        'author_user_id', 'occurred_at', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Build a new amendment row for a completed / aborted procedure (ADR-146
     * D-M). The caller ({@see ClinicalProcedure::amend()}) has already
     * validated eligibility via `ClinicalProcedureLifecycle`. `$reason` is
     * REQUIRED clinical data and lives on this row (never duplicated to the
     * audit entry).
     *
     * @param  list<string>  $changedFields
     */
    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $procedureId,
        string $patientId,
        string $encounterId,
        string $amendmentType,
        string $body,
        string $reason,
        array $changedFields,
        string $authorUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'procedure_id' => $procedureId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'amendment_type' => ProcedureAmendmentType::assert($amendmentType),
            'body' => $body,
            'reason' => $reason,
            'changed_fields' => $changedFields,
            'author_user_id' => $authorUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }
}
