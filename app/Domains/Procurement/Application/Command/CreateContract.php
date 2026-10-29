<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\ContractDatesInvalid;
use App\Domains\Procurement\Domain\Exception\ProcurementLineSetInvalid;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create a purchase contract as DRAFT (19-procurement-domain.md §40, §248;
 * ADR-176 D-C). `$contractId` is client-minted (UUIDv7). `>= 1` line; the
 * supplier must be ACTIVE; `effectiveDate < expiryDate`. STOCK lines are
 * validated + snapshotted via `InventoryApi::getItem`; SERVICE lines need a
 * non-blank description. Each line carries a non-negative `agreedUnitPrice` and
 * an optional positive `quantityCeiling`. Tenant-wide — no `facilityId` (the
 * `RegisterSupplier` precedent).
 *
 * @param  array<int, array<string, mixed>>  $lines
 */
final readonly class CreateContract implements Auditable, AuthorizesAgainst
{
    /** @var list<array<string, mixed>> */
    public array $lines;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public string $contractId,
        public string $actorId,
        public string $supplierId,
        public string $title,
        public string $effectiveDate,
        public string $expiryDate,
        array $lines,
        public ?string $reason = null,
        public ?string $terms = null,
        public string $currency = 'TZS',
        public ?string $idempotencyKey = null,
    ) {
        foreach (['contractId' => $contractId, 'actorId' => $actorId, 'supplierId' => $supplierId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreateContract::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException('CreateContract::$title must not be blank.');
        }

        $effectiveTs = strtotime($effectiveDate);
        $expiryTs = strtotime($expiryDate);
        if ($effectiveTs === false || $expiryTs === false) {
            throw new InvalidArgumentException('CreateContract::$effectiveDate and $expiryDate must be valid dates.');
        }
        if ($effectiveTs >= $expiryTs) {
            throw ContractDatesInvalid::effectiveNotBeforeExpiry($effectiveDate, $expiryDate);
        }

        $lines = array_values($lines);
        if ($lines === []) {
            throw ProcurementLineSetInvalid::empty('contract');
        }

        foreach ($lines as $i => $line) {
            $price = $line['agreedUnitPrice'] ?? null;
            if ($price === null || ! is_numeric((string) $price) || (float) $price < 0) {
                throw new InvalidArgumentException(sprintf('CreateContract line %d needs a non-negative `agreedUnitPrice` (§41).', $i + 1));
            }
            $ceiling = $line['quantityCeiling'] ?? null;
            if ($ceiling !== null && trim((string) $ceiling) !== '' && (! is_numeric((string) $ceiling) || (float) $ceiling <= 0)) {
                throw new InvalidArgumentException(sprintf('CreateContract line %d `quantityCeiling`, when given, must be a positive number (§41).', $i + 1));
            }
        }

        $this->lines = $lines;
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
            action: 'procurement.contract_created',
            actorId: $this->actorId,
            targetType: 'contract',
            targetId: $this->contractId,
            metadata: [
                'supplier_id' => $this->supplierId,
                'line_count' => count($this->lines),
            ],
        );
    }
}
