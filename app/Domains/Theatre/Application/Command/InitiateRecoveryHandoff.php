<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Initiate a recovery handoff for a surgical case (17-theatre-domain.md §90;
 * ADR-156 D-D). `$handoffId` is client-minted. `$destinationType` is
 * open-coded, normalised uppercase by the handler (RECOVERY / WARD / ICU /
 * EMERGENCY / OTHER — §90). `$patientCondition` / `$pendingActions` are
 * ROW-ONLY (ADR-136).
 */
final readonly class InitiateRecoveryHandoff implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $handoffId,
        public string $caseId,
        public string $actorId,
        public string $destinationType,
        public ?string $patientCondition = null,
        public ?string $pendingActions = null,
        public ?string $receivingTeamRef = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['handoffId' => $handoffId, 'caseId' => $caseId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("InitiateRecoveryHandoff::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($destinationType) === '') {
            throw new InvalidArgumentException('InitiateRecoveryHandoff::$destinationType must not be blank.');
        }

        if ($receivingTeamRef !== null && ! Str::isUuid($receivingTeamRef)) {
            throw new InvalidArgumentException('InitiateRecoveryHandoff::$receivingTeamRef must be a UUID or null (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.recovery_handoff_initiated',
            actorId: $this->actorId,
            targetType: 'recovery_handoff',
            targetId: $this->handoffId,
            metadata: [
                'case_id' => $this->caseId,
                'destination_type' => strtoupper(trim($this->destinationType)),
            ],
        );
    }
}
