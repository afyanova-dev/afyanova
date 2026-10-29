<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Exception\InvalidPayerContractDates;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a new payer contract in DRAFT status (21-insurance-and-payer-domain.md §49; ADR-203).
 * Class-level `create` (`insurance.contracts.manage`).
 */
final readonly class CreatePayerContract implements Auditable, AuthorizesAgainst
{
    public string $name;

    public ?string $reference;

    public ?string $terms;

    public function __construct(
        public string $contractId,
        public string $actorId,
        public string $payerId,
        string $name,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo = null,
        ?string $reference = null,
        public ?string $facilityId = null,
        ?string $terms = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['contractId' => $contractId, 'actorId' => $actorId, 'payerId' => $payerId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreatePayerContract::\${$field} must be a UUID (IC §3).");
            }
        }

        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('CreatePayerContract::$facilityId must be a UUID or null (IC §3).');
        }

        $this->name = trim($name);
        if ($this->name === '') {
            throw new InvalidArgumentException('CreatePayerContract::$name must not be empty.');
        }

        $this->reference = self::clean($reference);
        $this->terms = self::clean($terms);

        if ($effectiveTo !== null && $effectiveTo->lt($effectiveFrom)) {
            throw InvalidPayerContractDates::window(
                $effectiveFrom->toDateString(),
                $effectiveTo->toDateString(),
            );
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
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.contract_created',
            actorId: $this->actorId,
            targetType: 'contract',
            targetId: $this->contractId,
            metadata: [
                'payer_id' => $this->payerId,
                'name' => $this->name,
                'effective_from' => $this->effectiveFrom->toDateString(),
                'effective_to' => $this->effectiveTo?->toDateString(),
            ],
        );
    }
}
