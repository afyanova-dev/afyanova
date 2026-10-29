<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Move stock between two locations of the same facility (Phase 8).
 *
 * This is how a main store hands stock to a pharmacy counter, or a ward
 * returns it. The stock is not consumed and nothing is gained or lost, so no
 * second approver is needed — the two ledger sides are written together in one
 * transaction. `$quantity` is in the item's BASE unit.
 *
 * `$transferId` is client-minted (UUIDv7) and is the id both movement rows
 * carry as `source_ref`. `facility_id` is taken from the LOCATIONS by the
 * handler, never from this command.
 */
final readonly class TransferStock implements Auditable, AuthorizesAgainst
{
    /** Beyond this, a backdated transfer must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $transferId,
        public string $actorId,
        public string $itemId,
        public string $fromLocationId,
        public string $toLocationId,
        public string $quantity,
        public ?string $batchNumber = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'transferId' => $transferId,
            'actorId' => $actorId,
            'itemId' => $itemId,
            'fromLocationId' => $fromLocationId,
            'toLocationId' => $toLocationId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("TransferStock::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($fromLocationId === $toLocationId) {
            throw new InvalidArgumentException('TransferStock::$toLocationId must differ from the source location.');
        }

        if (! is_numeric($quantity) || ! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('TransferStock::$quantity must be a positive decimal string.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('TransferStock::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('TransferStock::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'TransferStock::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'transfer';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_transferred',
            actorId: $this->actorId,
            targetType: 'stock_transfer',
            targetId: $this->transferId,
            metadata: [
                'item_id' => $this->itemId,
                'from_location_id' => $this->fromLocationId,
                'to_location_id' => $this->toLocationId,
                'quantity' => $this->quantity,
                'batch_number' => $this->batchNumber !== null && trim($this->batchNumber) !== ''
                    ? trim($this->batchNumber)
                    : null,
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
