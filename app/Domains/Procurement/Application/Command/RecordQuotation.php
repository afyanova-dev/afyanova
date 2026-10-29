<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Enter a supplier's quotation against an ISSUED RFQ (19-procurement-domain.md
 * §32, §33; ADR-173 D-D). Procurement-STAFF-entered in Proc.2a (no supplier
 * portal). `$quotationId` is client-minted (UUIDv7). The supplier must be
 * INVITED; one quotation per (RFQ, supplier). `>= 1` line, each citing an
 * `rfqLineId`, with a non-negative `unitPrice` and a positive `offeredQty`
 * (MAY be < the RFQ line quantity → §39 partial).
 *
 * @param  array<int, array<string, mixed>>  $lines
 */
final readonly class RecordQuotation implements Auditable, AuthorizesAgainst
{
    /** @var list<array<string, mixed>> */
    public array $lines;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public string $quotationId,
        public string $actorId,
        public string $rfqId,
        public string $supplierId,
        array $lines,
        public string $currency = 'TZS',
        public ?string $supplierQuoteRef = null,
        public ?string $validityDate = null,
        public ?int $leadTimeDays = null,
        public ?string $paymentTerms = null,
        public ?string $deliveryTerms = null,
        public ?string $notes = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'quotationId' => $quotationId, 'actorId' => $actorId,
            'rfqId' => $rfqId, 'supplierId' => $supplierId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordQuotation::\${$name} must be a UUID (IC §3).");
            }
        }

        $lines = array_values($lines);
        if ($lines === []) {
            throw new InvalidArgumentException('RecordQuotation requires at least one line (§32).');
        }

        foreach ($lines as $i => $line) {
            $rfqLineId = $line['rfqLineId'] ?? null;
            if (! is_string($rfqLineId) || ! Str::isUuid($rfqLineId)) {
                throw new InvalidArgumentException(sprintf('RecordQuotation line %d requires an `rfqLineId` UUID (§34).', $i + 1));
            }
            $price = $line['unitPrice'] ?? null;
            if ($price === null || ! is_numeric((string) $price) || (float) $price < 0) {
                throw new InvalidArgumentException(sprintf('RecordQuotation line %d requires a non-negative `unitPrice` (§32).', $i + 1));
            }
            $qty = $line['offeredQty'] ?? null;
            if ($qty === null || ! is_numeric((string) $qty) || (float) $qty <= 0) {
                throw new InvalidArgumentException(sprintf('RecordQuotation line %d requires a positive `offeredQty` (§39).', $i + 1));
            }
        }

        if (trim($currency) === '') {
            throw new InvalidArgumentException('RecordQuotation::$currency must not be blank.');
        }

        if ($leadTimeDays !== null && $leadTimeDays < 0) {
            throw new InvalidArgumentException('RecordQuotation::$leadTimeDays must not be negative.');
        }

        $this->lines = $lines;
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): string
    {
        return $this->rfqId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.quotation_recorded',
            actorId: $this->actorId,
            targetType: 'quotation',
            targetId: $this->quotationId,
            metadata: [
                'rfq_id' => $this->rfqId,
                'supplier_id' => $this->supplierId,
                'line_count' => count($this->lines),
            ],
        );
    }
}
