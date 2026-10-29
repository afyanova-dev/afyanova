<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ValueObject\BeneficiaryRelationship;
use App\Domains\Insurance\Domain\ValueObject\CoveragePriority;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Register a patient's coverage under a payer plan
 * (21-insurance-and-payer-domain.md §13, §14, §17–§21; ADR-199 D-B).
 * Class-level `manage` (`insurance.members.manage`).
 *
 * The handler verifies the patient + plan exist, both payer & plan are ACTIVE,
 * the coverage window sits within the plan window, and the principal reference
 * is consistent with the relationship (ADR-199 D-E).
 */
final readonly class RegisterMemberCoverage implements Auditable, AuthorizesAgainst
{
    public string $relationship;

    public string $priority;

    public CarbonImmutable $effectiveFrom;

    public ?CarbonImmutable $effectiveTo;

    public ?string $memberNumber;

    public ?string $policyNumber;

    public ?string $cardNumber;

    public function __construct(
        public string $memberCoverageId,
        public string $actorId,
        public string $patientId,
        public string $planId,
        string $relationship,
        string $priority,
        string $effectiveFrom,
        ?string $effectiveTo = null,
        ?string $memberNumber = null,
        ?string $policyNumber = null,
        ?string $cardNumber = null,
        public ?string $principalMemberCoverageId = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['memberCoverageId' => $memberCoverageId, 'actorId' => $actorId, 'patientId' => $patientId, 'planId' => $planId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RegisterMemberCoverage::\${$field} must be a UUID (IC §3).");
            }
        }
        if ($principalMemberCoverageId !== null && ! Str::isUuid($principalMemberCoverageId)) {
            throw new InvalidArgumentException('RegisterMemberCoverage::$principalMemberCoverageId must be a UUID or null (IC §3).');
        }

        $this->relationship = BeneficiaryRelationship::assert($relationship);
        $this->priority = CoveragePriority::assert($priority);

        $from = self::parseDate($effectiveFrom, 'effectiveFrom');
        if ($from === null) {
            throw new InvalidArgumentException('RegisterMemberCoverage::$effectiveFrom is required.');
        }
        $to = $effectiveTo !== null ? self::parseDate($effectiveTo, 'effectiveTo') : null;
        if ($to !== null && $from->gt($to)) {
            throw new InvalidArgumentException('RegisterMemberCoverage::$effectiveFrom must not be after $effectiveTo.');
        }

        $this->effectiveFrom = $from;
        $this->effectiveTo = $to;
        $this->memberNumber = self::clean($memberNumber);
        $this->policyNumber = self::clean($policyNumber);
        $this->cardNumber = self::clean($cardNumber);
    }

    private static function parseDate(string $value, string $field): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException("RegisterMemberCoverage::\${$field} must be a valid date.");
        }
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.member_coverage_registered',
            actorId: $this->actorId,
            targetType: 'member_coverage',
            targetId: $this->memberCoverageId,
            metadata: [
                'patient_id' => $this->patientId,
                'plan_id' => $this->planId,
                'priority' => $this->priority,
                'relationship' => $this->relationship,
            ],
        );
    }
}
