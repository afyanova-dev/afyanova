<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RegisterQueueCommand implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $facilityId,
        public string $code,
        public string $name,
        public string $actorId,
        public ?string $departmentId = null,
        public ?string $serviceCapability = null,
        public string $ticketPrefix = 'A',
        public bool $isActive = true,
    ) {
        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RegisterQueueCommand::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'register';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'queue.configuration.saved',
            facilityId: $this->facilityId,
            actorId: $this->actorId,
            targetType: 'queue',
            targetId: $this->code,
            metadata: [
                'code' => $this->code,
                'department_id' => $this->departmentId,
                'service_capability' => $this->serviceCapability,
                'ticket_prefix' => $this->ticketPrefix,
                'is_active' => $this->isActive,
            ],
        );
    }
}
