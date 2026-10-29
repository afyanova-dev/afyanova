<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Update a supplier's contact / commercial details (19-procurement-domain.md
 * §10; ADR-171 D-D — F5e). IMMUTABLE via this command: id, code, status.
 *
 * @param  list<string>|null  $categories
 */
final readonly class UpdateSupplier implements Auditable, AuthorizesAgainst
{
    /** @var list<string>|null */
    public ?array $categories;

    /**
     * @param  list<string>|null  $categories
     */
    public function __construct(
        public string $supplierId,
        public string $actorId,
        public ?string $name = null,
        public ?string $registrationNumber = null,
        public ?string $contactName = null,
        public ?string $contactEmail = null,
        public ?string $contactPhone = null,
        public ?string $address = null,
        public ?string $taxIdentifier = null,
        public ?string $paymentTerms = null,
        ?array $categories = null,
    ) {
        if (! Str::isUuid($supplierId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateSupplier ids must be UUIDs (IC §3).');
        }

        $this->categories = $categories === null
            ? null
            : array_map(static fn ($c): string => (string) $c, $categories);
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->supplierId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.supplier_updated',
            actorId: $this->actorId,
            targetType: 'supplier',
            targetId: $this->supplierId,
        );
    }
}
