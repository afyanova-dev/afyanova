<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Discontinue an active PrescribingDecision (ADR-143 D-J / D-S).
 */
final readonly class DiscontinuePrescribingDecision implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $prescribingDecisionId,
        public string $actorId,
        public string $reason,
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($prescribingDecisionId)) {
            throw new InvalidArgumentException('DiscontinuePrescribingDecision::$prescribingDecisionId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('DiscontinuePrescribingDecision::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('DiscontinuePrescribingDecision::$reason is required.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('DiscontinuePrescribingDecision::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('DiscontinuePrescribingDecision::$occurredAt must not be in the future.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'discontinue';
    }

    public function subjectId(): string
    {
        return $this->prescribingDecisionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.prescribing_decisions.discontinued',
            actorId: $this->actorId,
            targetType: 'prescribing_decision',
            targetId: $this->prescribingDecisionId,
            reason: null,
        );
    }
}
