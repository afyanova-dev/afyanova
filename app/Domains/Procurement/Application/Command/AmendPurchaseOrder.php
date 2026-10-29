<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Model\PoAmendment;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Record one controlled change to an ISSUED purchase order
 * (19-procurement-domain.md §67, §68, §231, §232; ADR-171 D-H / D-I).
 *
 * ONE structured field per amendment (the `field_changed` / `previous_value` /
 * `new_value` row shape). `amendmentType` fixes the target column + level:
 *
 *   QUANTITY          → line `ordered_qty`          (poLineId required; > 0)
 *   PRICE             → line `unit_price`           (poLineId required; >= 0)
 *   DELIVERY_DATE     → line `required_date`        (poLineId required; date or clear)
 *   DELIVERY_LOCATION → line `delivery_location_ref`(poLineId required; uuid or clear)
 *   NOTES             → header `notes`              (poLineId MUST be null; text or clear)
 *
 * `OTHER` is reserved (the event vocab / Proc.4) — NOT accepted here. There is
 * NO value-threshold reapproval (F3d — deliberately weaker than §95 / Inv 28).
 * `reason` is mandatory + ROW-ONLY + on the audit row, NEVER the event (ADR-136).
 */
final readonly class AmendPurchaseOrder implements Auditable, AuthorizesAgainst
{
    private const LINE_LEVEL = [
        PoAmendment::TYPE_QUANTITY,
        PoAmendment::TYPE_PRICE,
        PoAmendment::TYPE_DELIVERY_DATE,
        PoAmendment::TYPE_DELIVERY_LOCATION,
    ];

    public function __construct(
        public string $purchaseOrderId,
        public string $actorId,
        public string $amendmentType,
        public string $reason,
        public ?string $newValue = null,
        public ?string $poLineId = null,
    ) {
        if (! Str::isUuid($purchaseOrderId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AmendPurchaseOrder ids must be UUIDs (IC §3).');
        }

        if (! in_array($amendmentType, self::supportedTypes(), true)) {
            throw new InvalidArgumentException(sprintf(
                'AmendPurchaseOrder::$amendmentType must be one of %s (OTHER is reserved — ADR-171 D-I).',
                implode(' | ', self::supportedTypes()),
            ));
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('AmendPurchaseOrder::$reason must not be blank (§67).');
        }

        if ($this->isLineLevel()) {
            if ($poLineId === null || ! Str::isUuid($poLineId)) {
                throw new InvalidArgumentException("AmendPurchaseOrder::\$poLineId (a UUID) is required for a {$amendmentType} amendment.");
            }
        } elseif ($poLineId !== null) {
            throw new InvalidArgumentException("AmendPurchaseOrder::\$poLineId must be null for a {$amendmentType} amendment (it changes the header).");
        }

        $this->assertNewValueShape();
    }

    /** @return list<string> */
    public static function supportedTypes(): array
    {
        return [...self::LINE_LEVEL, PoAmendment::TYPE_NOTES];
    }

    public function isLineLevel(): bool
    {
        return in_array($this->amendmentType, self::LINE_LEVEL, true);
    }

    /** The `pcr_po_lines` / `pcr_purchase_orders` column this amendment targets. */
    public function targetField(): string
    {
        return match ($this->amendmentType) {
            PoAmendment::TYPE_QUANTITY => 'ordered_qty',
            PoAmendment::TYPE_PRICE => 'unit_price',
            PoAmendment::TYPE_DELIVERY_DATE => 'required_date',
            PoAmendment::TYPE_DELIVERY_LOCATION => 'delivery_location_ref',
            default => 'notes',
        };
    }

    public function policyAbility(): string
    {
        return 'amend';
    }

    public function subjectId(): string
    {
        return $this->purchaseOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.order_amended',
            actorId: $this->actorId,
            targetType: 'purchase_order',
            targetId: $this->purchaseOrderId,
            reason: trim($this->reason),
            metadata: [
                'amendment_type' => $this->amendmentType,
                'field_changed' => $this->targetField(),
                'po_line_id' => $this->poLineId,
            ],
        );
    }

    private function assertNewValueShape(): void
    {
        $value = $this->newValue;

        switch ($this->amendmentType) {
            case PoAmendment::TYPE_QUANTITY:
                if ($value === null || ! is_numeric($value) || (float) $value <= 0) {
                    throw new InvalidArgumentException('AmendPurchaseOrder QUANTITY $newValue must be a positive number (§51).');
                }
                break;
            case PoAmendment::TYPE_PRICE:
                if ($value === null || ! is_numeric($value) || (float) $value < 0) {
                    throw new InvalidArgumentException('AmendPurchaseOrder PRICE $newValue must be a non-negative number (§51).');
                }
                break;
            case PoAmendment::TYPE_DELIVERY_DATE:
                if ($value !== null && trim($value) !== '') {
                    try {
                        CarbonImmutable::parse($value);
                    } catch (Throwable) {
                        throw new InvalidArgumentException('AmendPurchaseOrder DELIVERY_DATE $newValue must be a date or null.');
                    }
                }
                break;
            case PoAmendment::TYPE_DELIVERY_LOCATION:
                if ($value !== null && trim($value) !== '' && ! Str::isUuid($value)) {
                    throw new InvalidArgumentException('AmendPurchaseOrder DELIVERY_LOCATION $newValue must be a UUID or null.');
                }
                break;
        }
    }
}
