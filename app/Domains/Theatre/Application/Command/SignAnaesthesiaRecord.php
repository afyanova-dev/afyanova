<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sign an anaesthesia record — content is frozen thereafter
 * (17-theatre-domain.md §36–§40; ADR-155 D-C). Allowed from DRAFT only. Needs
 * the senior key `theatre.anaesthesia.sign`.
 */
final readonly class SignAnaesthesiaRecord implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $recordId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($recordId)) {
            throw new InvalidArgumentException('SignAnaesthesiaRecord::$recordId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SignAnaesthesiaRecord::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'sign';
    }

    public function subjectId(): string
    {
        return $this->recordId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.anaesthesia_record_signed',
            actorId: $this->actorId,
            targetType: 'anaesthesia_record',
            targetId: $this->recordId,
        );
    }
}
