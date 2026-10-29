<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Exception\ResultAmendmentIncomplete;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Invalidate a laboratory result (14-laboratory-domain.md §63; ADR-160 D-F) —
 * incorrect specimen, instrument problem, analytical error. Records the reason
 * on `lab_result_amendments` (type CANCELLATION) and optionally links the
 * replacement result. `$reason` is free text, row-only + audit, not in the
 * event.
 */
final readonly class CancelResult implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $resultId,
        public string $amendmentId,
        public string $actorId,
        public string $reason,
        public ?string $replacementResultId = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['resultId' => $resultId, 'amendmentId' => $amendmentId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CancelResult::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($reason) === '') {
            throw ResultAmendmentIncomplete::reasonRequired('CancelResult');
        }

        if ($replacementResultId !== null && ! Str::isUuid($replacementResultId)) {
            throw new InvalidArgumentException('CancelResult::$replacementResultId must be a UUID or null (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'amend';
    }

    public function subjectId(): string
    {
        return $this->resultId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.result_cancelled',
            actorId: $this->actorId,
            targetType: 'lab_result',
            targetId: $this->resultId,
            reason: trim($this->reason),
            metadata: ['amendment_id' => $this->amendmentId],
        );
    }
}
