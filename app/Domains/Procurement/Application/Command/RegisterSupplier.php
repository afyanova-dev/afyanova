<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Register a supplier (19-procurement-domain.md §10, §13; ADR-171 D-D). Enters
 * ACTIVE (PROSPECT + the §13 onboarding flow deferred to Proc.4). `$supplierId`
 * is client-minted (UUIDv7).
 *
 * @param  list<string>  $categories
 */
final readonly class RegisterSupplier implements Auditable, AuthorizesAgainst
{
    /** @var list<string> */
    public array $categories;

    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        public string $supplierId,
        public string $actorId,
        public string $code,
        public string $name,
        public ?string $registrationNumber = null,
        public ?string $contactName = null,
        public ?string $contactEmail = null,
        public ?string $contactPhone = null,
        public ?string $address = null,
        public ?string $taxIdentifier = null,
        public ?string $paymentTerms = null,
        array $categories = [],
        public ?string $idempotencyKey = null,
        public ?string $licenceNumber = null,
        public ?string $licenceAuthority = null,
        public ?string $licenceExpiryDate = null,
    ) {
        foreach (['supplierId' => $supplierId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RegisterSupplier::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($code) === '' || trim($name) === '') {
            throw new InvalidArgumentException('RegisterSupplier::$code and $name must not be blank.');
        }

        $this->categories = array_values(array_unique(array_map(
            static fn ($c): string => (string) $c,
            $categories,
        )));
    }

    public function policyAbility(): string
    {
        // Class-level check (the supplier does not exist yet) — a distinct
        // method name from the per-subject `manage`; both gate the SAME
        // permission key `procurement.suppliers.manage` (ADR-171 D-D / D-M).
        return 'register';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.supplier_registered',
            actorId: $this->actorId,
            targetType: 'supplier',
            targetId: $this->supplierId,
            metadata: ['code' => strtoupper(trim($this->code))],
        );
    }
}
