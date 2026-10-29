<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class UpdateBed implements Auditable, AuthorizesAgainst
{
    /**
     * @param  list<string>|array<string, mixed>  $features
     */
    public function __construct(
        public string $bedId,
        public string $actorId,
        public string $label,
        public string $bedClass,
        public array $features = [],
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($bedId)) {
            throw new InvalidArgumentException('UpdateBed::$bedId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateBed::$actorId must be a UUID (IC §3).');
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException('UpdateBed::$label must not be empty.');
        }

        if (trim($bedClass) === '') {
            throw new InvalidArgumentException('UpdateBed::$bedClass must not be empty.');
        }
    }

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->bedId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.bed_updated',
            actorId: $this->actorId,
            targetType: 'bed',
            targetId: $this->bedId,
            metadata: [
                'label' => $this->label,
                'bed_class' => $this->bedClass,
            ],
        );
    }
}
