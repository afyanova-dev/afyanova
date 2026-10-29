<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Change a payer's display name and/or contact fields (ADR-198 D-G).
 * Per-subject `manage`. `code` / `payer_type` are SET-ONCE — not here.
 *
 * A null field is left unchanged; an empty string clears a contact field.
 */
final readonly class UpdatePayer implements Auditable, AuthorizesAgainst
{
    public ?string $name;

    public function __construct(
        public string $payerId,
        public string $actorId,
        ?string $name = null,
        public ?string $contactName = null,
        public ?string $contactEmail = null,
        public ?string $contactPhone = null,
    ) {
        if (! Str::isUuid($payerId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdatePayer ids must be UUIDs (IC §3).');
        }

        if ($name !== null && trim($name) === '') {
            throw new InvalidArgumentException('UpdatePayer::$name, when given, must not be blank.');
        }

        $this->name = $name !== null ? trim($name) : null;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->payerId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.payer_updated',
            actorId: $this->actorId,
            targetType: 'payer',
            targetId: $this->payerId,
        );
    }
}
