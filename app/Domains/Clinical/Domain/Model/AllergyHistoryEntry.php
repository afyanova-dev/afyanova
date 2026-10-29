<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Database\Factories\AllergyHistoryEntryFactory;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * One immutable PRE-CHANGE snapshot of an {@see Allergy}'s clinical state, taken
 * the instant before an `UpdateAllergy` (content) or `VerifyAllergy`
 * (verification) applies its change (12-clinical-domain.md §15, §74–§75, §162;
 * ADR-141 D-J).
 *
 * Its OWN small tenant-scoped aggregate — the same pattern as
 * `ProblemHistoryEntry` (ADR-140) / `ClinicalNoteAmendment` (ADR-134). It
 * carries NO domain event (the parent `Allergy` records `AllergyUpdated` /
 * `AllergyVerified`). It is persisted alongside the allergy by
 * `EloquentAllergyRepository::save()`.
 *
 * `ResolveAllergy` does NOT write a history row (ADR-141 D-J — resolution is
 * preserved by the `AllergyResolved` event + the `clinical.allergies.resolved`
 * audit + the set-once `clinical_status` / `resolved_at` columns on
 * `clin_allergies`, the ADR-140 D-P `ResolveProblem` precedent).
 *
 * `change_kind` distinguishes the two history-writing paths:
 *   - `CONTENT`      — from `UpdateAllergy`; `change_type` is
 *                      `REVISION` | `CORRECTION`.
 *   - `VERIFICATION` — from `VerifyAllergy`; `change_type` is the coded target
 *                      `CONFIRMED` | `REFUTED`.
 * `reason` is REQUIRED clinical data for a `CORRECTION` and for a `REFUTED`
 * verification (null otherwise); it lives on this row ONLY — never on the audit
 * entry or the domain event (ADR-141 D-Q / D-R).
 *
 * `allergy_id` carries a real foreign key to `clin_allergies` — an INTRA-domain
 * reference (both `clin_`), permitted by IC §10 (only cross-prefix FKs are
 * forbidden). `patient_id` / `encounter_id` / `author_user_id` are plain uuids,
 * denormalised from the parent, with NO FK.
 *
 * @property string $change_kind CONTENT | VERIFICATION
 * @property string|null $change_type REVISION | CORRECTION | CONFIRMED | REFUTED
 * @property string $clinical_status AllergyStatus value at snapshot time
 * @property string $verification_status AllergyVerificationStatus value at snapshot time
 * @property CarbonImmutable|null $onset_date
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 */
final class AllergyHistoryEntry extends AggregateRoot
{
    /** @use HasFactory<AllergyHistoryEntryFactory> */
    use HasFactory;

    public const CHANGE_KIND_CONTENT = 'CONTENT';

    public const CHANGE_KIND_VERIFICATION = 'VERIFICATION';

    protected $table = 'clin_allergy_history';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'allergy_id', 'patient_id', 'encounter_id',
        'change_kind', 'change_type',
        'category', 'substance_name', 'substance_code', 'substance_code_system',
        'reaction_description', 'severity',
        'clinical_status', 'verification_status', 'onset_date',
        'reason', 'author_user_id',
        'occurred_at', 'recorded_at',
    ];

    protected static function newFactory(): AllergyHistoryEntryFactory
    {
        return AllergyHistoryEntryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onset_date' => 'immutable_date',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Capture the COMPLETE pre-change clinical state of `$allergy` (ADR-141
     * D-J). Called by {@see Allergy::revise()} / {@see Allergy::verify()} BEFORE
     * the new values are applied.
     *
     * `$reason` is the mandatory `CORRECTION` / `REFUTED` rationale (null for a
     * `REVISION` and for a `CONFIRMED` verification) and is CLINICAL data that
     * lives on this row only — never on the audit, never on the event (ADR-141
     * D-Q / D-R). `$authorUserId`, `$occurredAt` and `$recordedAt` describe THE
     * CHANGE, not the snapshot (server time — ADR-141 D-H).
     */
    public static function fromPreChangeState(
        Allergy $allergy,
        string $changeKind,
        string $changeType,
        ?string $reason,
        string $authorUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $allergy->tenant_id,
            'facility_id' => (string) $allergy->facility_id,
            'allergy_id' => (string) $allergy->id,
            'patient_id' => (string) $allergy->patient_id,
            'encounter_id' => (string) $allergy->encounter_id,
            'change_kind' => $changeKind,
            'change_type' => $changeType,
            'category' => (string) $allergy->category,
            'substance_name' => (string) $allergy->substance_name,
            'substance_code' => $allergy->substance_code,
            'substance_code_system' => $allergy->substance_code_system,
            'reaction_description' => $allergy->reaction_description,
            'severity' => $allergy->severity,
            'clinical_status' => (string) $allergy->clinical_status,
            'verification_status' => (string) $allergy->verification_status,
            'onset_date' => $allergy->onset_date,
            'reason' => $reason,
            'author_user_id' => $authorUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** The pre-change substance as a value object. */
    public function substance(): AllergySubstance
    {
        return AllergySubstance::of(
            name: (string) $this->substance_name,
            code: $this->substance_code,
            codeSystem: $this->substance_code_system,
        );
    }
}
