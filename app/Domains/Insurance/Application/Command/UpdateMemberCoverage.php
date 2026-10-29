<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ValueObject\BeneficiaryRelationship;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Correct a member coverage's external identifiers, relationship, and/or expiry
 * (ADR-199 D-B). Per-subject `manage`. `patient_id` / `plan_id` / `payer_id` /
 * `priority` / `effective_from` are SET-ONCE — not here. A null argument leaves
 * that field unchanged; an empty string clears an external identifier.
 */
final readonly class UpdateMemberCoverage implements Auditable, AuthorizesAgainst
{
    public ?string $relationship;

    public ?CarbonImmutable $effectiveTo;

    public function __construct(
        public string $memberCoverageId,
        public string $actorId,
        public ?string $memberNumber = null,
        public ?string $policyNumber = null,
        public ?string $cardNumber = null,
        ?string $relationship = null,
        ?string $effectiveTo = null,
    ) {
        if (! Str::isUuid($memberCoverageId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateMemberCoverage ids must be UUIDs (IC §3).');
        }

        $this->relationship = $relationship !== null ? BeneficiaryRelationship::assert($relationship) : null;

        if ($effectiveTo !== null && trim($effectiveTo) !== '') {
            try {
                $this->effectiveTo = CarbonImmutable::parse($effectiveTo)->startOfDay();
            } catch (\Throwable) {
                throw new InvalidArgumentException('UpdateMemberCoverage::$effectiveTo must be a valid date.');
            }
        } else {
            $this->effectiveTo = null;
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->memberCoverageId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.member_coverage_updated',
            actorId: $this->actorId,
            targetType: 'member_coverage',
            targetId: $this->memberCoverageId,
        );
    }
}
