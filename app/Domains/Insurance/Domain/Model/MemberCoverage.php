<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Database\Factories\MemberCoverageFactory;
use App\Domains\Insurance\Domain\ValueObject\MemberCoverageStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A patient's participation in a payer plan — the membership record
 * (21-insurance-and-payer-domain.md §13, §14, §17–§23, §247; Invariant 2;
 * ADR-199 D-A).
 *
 * Distinct from eligibility (§24 — is it active *now*?, Ins.3) and coverage
 * rules (§31 — is *this service* covered?, Ins.3). `plan_id` / `payer_id` are
 * SET-ONCE (`payer_id` copied from the plan at registration — a plan's payer
 * never changes). The external `member_number` / `policy_number` / `card_number`
 * never replace internal identity (§16). Terminal state via {@see self::terminate()};
 * the row is never deleted (§319). No events — §234's payer event model begins
 * at eligibility; the audit log covers registration.
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $plan_id
 * @property string $payer_id
 * @property string|null $member_number
 * @property string|null $policy_number
 * @property string|null $card_number
 * @property string $relationship
 * @property string|null $principal_member_coverage_id
 * @property string $priority
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $status
 * @property CarbonImmutable|null $terminated_at
 * @property string|null $terminated_by_user_id
 * @property string|null $termination_reason
 * @property string|null $created_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class MemberCoverage extends AggregateRoot
{
    /** @use HasFactory<MemberCoverageFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = MemberCoverageStatus::ACTIVE;

    public const STATUS_TERMINATED = MemberCoverageStatus::TERMINATED;

    protected $table = 'ins_member_coverages';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'patient_id', 'plan_id', 'payer_id',
        'member_number', 'policy_number', 'card_number',
        'relationship', 'principal_member_coverage_id', 'priority',
        'effective_from', 'effective_to',
        'status', 'terminated_at', 'terminated_by_user_id', 'termination_reason',
        'created_by_user_id',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): MemberCoverageFactory
    {
        return MemberCoverageFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'terminated_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function register(
        string $id,
        string $tenantId,
        string $patientId,
        string $planId,
        string $payerId,
        ?string $memberNumber,
        ?string $policyNumber,
        ?string $cardNumber,
        string $relationship,
        ?string $principalMemberCoverageId,
        string $priority,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo,
        string $createdByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'patient_id' => $patientId,
            'plan_id' => $planId,
            'payer_id' => $payerId,
            'member_number' => $memberNumber,
            'policy_number' => $policyNumber,
            'card_number' => $cardNumber,
            'relationship' => $relationship,
            'principal_member_coverage_id' => $principalMemberCoverageId,
            'priority' => $priority,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'status' => self::STATUS_ACTIVE,
            'terminated_at' => null,
            'terminated_by_user_id' => null,
            'termination_reason' => null,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * Patch the external identifiers, the relationship, and/or the expiry.
     * A null argument leaves that field unchanged; an empty string clears an
     * external identifier. `patient_id` / `plan_id` / `payer_id` / `priority` /
     * `effective_from` are SET-ONCE — never here.
     */
    public function amend(
        ?string $memberNumber,
        ?string $policyNumber,
        ?string $cardNumber,
        ?string $relationship,
        ?CarbonImmutable $effectiveTo,
    ): void {
        if ($memberNumber !== null) {
            $this->member_number = trim($memberNumber) === '' ? null : trim($memberNumber);
        }
        if ($policyNumber !== null) {
            $this->policy_number = trim($policyNumber) === '' ? null : trim($policyNumber);
        }
        if ($cardNumber !== null) {
            $this->card_number = trim($cardNumber) === '' ? null : trim($cardNumber);
        }
        if ($relationship !== null) {
            $this->relationship = $relationship;
        }
        if ($effectiveTo !== null) {
            $this->effective_to = $effectiveTo;
        }
    }

    /** ACTIVE → TERMINATED. Idempotent — a no-op when already TERMINATED. */
    public function terminate(string $byUserId, ?string $reason, CarbonImmutable $at): void
    {
        if ($this->status === self::STATUS_TERMINATED) {
            return;
        }

        $this->status = self::STATUS_TERMINATED;
        $this->terminated_at = $at;
        $this->terminated_by_user_id = $byUserId;
        $this->termination_reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
