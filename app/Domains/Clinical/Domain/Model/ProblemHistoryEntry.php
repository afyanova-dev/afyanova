<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Database\Factories\ProblemHistoryEntryFactory;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * One immutable PRE-CHANGE snapshot of a {@see Problem}'s clinical state, taken
 * the instant before an `UpdateProblem` applies its change (12-clinical-domain.md
 * §15, §74, §162; ADR-140 D-D / D-P).
 *
 * Its OWN small tenant-scoped aggregate — the same pattern as
 * `ClinicalNoteAmendment` (ADR-134) / `PatientIdentityHistory` (ADR-128). It
 * carries NO domain event (the parent `Problem` records `ProblemUpdated`). It is
 * persisted alongside the problem by `EloquentProblemRepository::save()`.
 *
 * The row is written ONCE and NEVER updated or deleted — `clin_problem_history`
 * is append-only at the database (`Rls::enableAppendOnly`), so the `version`
 * column stays 0 for the row's whole life (it exists only to satisfy the
 * aggregate table shape, IC §9.1 / §10).
 *
 * `ResolveProblem` does NOT write a history row (ADR-140 D-P — `change_type` is
 * `REVISION` | `CORRECTION` only; the resolution is preserved by the
 * `ProblemResolved` event + the `clinical.problems.resolved` audit + the
 * set-once `status` / `resolved_at` columns on `clin_problems`).
 *
 * @domain      Clinical
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required — copied from the parent problem's originating facility
 *              context (ADR-140 D-B); never client input (Invariant 12).
 *
 * @property string $change_type REVISION | CORRECTION (a ProblemChangeType value)
 * @property string $status ACTIVE | RESOLVED at snapshot time (a ProblemStatus value)
 * @property CarbonImmutable|null $onset_date
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 */
final class ProblemHistoryEntry extends AggregateRoot
{
    /** @use HasFactory<ProblemHistoryEntryFactory> */
    use HasFactory;

    protected $table = 'clin_problem_history';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'problem_id', 'patient_id', 'encounter_id',
        'change_type', 'code', 'code_system', 'description', 'status',
        'onset_date', 'resolved_at', 'reason',
        'author_user_id', 'occurred_at', 'recorded_at',
    ];

    protected static function newFactory(): ProblemHistoryEntryFactory
    {
        return ProblemHistoryEntryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onset_date' => 'immutable_date',
            'resolved_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Capture the complete pre-change clinical state of `$problem` (ADR-140 D-D
     * clarification lock — "the previous complete clinical state"). Called by
     * {@see Problem::revise()} BEFORE the new values are applied. `$reason` is
     * the mandatory `CORRECTION` rationale (null for a `REVISION`) and is
     * CLINICAL data that lives on this row only — never on the audit, never on
     * the event (ADR-140 D-N / D-M). `$authorUserId`, `$occurredAt` and
     * `$recordedAt` describe THE CHANGE, not the snapshot.
     */
    public static function fromPreChangeState(
        Problem $problem,
        string $changeType,
        ?string $reason,
        string $authorUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $problem->tenant_id,
            'facility_id' => (string) $problem->facility_id,
            'problem_id' => (string) $problem->id,
            'patient_id' => (string) $problem->patient_id,
            'encounter_id' => (string) $problem->encounter_id,
            'change_type' => $changeType,
            'code' => $problem->code,
            'code_system' => $problem->code_system,
            'description' => (string) $problem->description,
            'status' => $problem->status,
            'onset_date' => $problem->onset_date,
            'resolved_at' => $problem->resolved_at,
            'reason' => $reason,
            'author_user_id' => $authorUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** The pre-change coding as a value object. */
    public function coding(): ProblemCode
    {
        return ProblemCode::of($this->code, $this->code_system);
    }
}
