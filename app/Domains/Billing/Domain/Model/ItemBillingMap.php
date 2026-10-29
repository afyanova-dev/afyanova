<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\ItemBillingMapFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One `inv_items.id → billing_service_id` mapping row
 * (20-billing-and-payments-domain.md §237; ADR-189 D-A).
 *
 * `MapItemToBillingService` supersedes the prior active row for an item
 * (old.active := false, then a new active row). No events (Bill.3a is
 * configuration).
 *
 * @domain      Billing
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $inv_item_id
 * @property string $billing_service_id
 * @property bool $active
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class ItemBillingMap extends AggregateRoot
{
    /** @use HasFactory<ItemBillingMapFactory> */
    use HasFactory;

    protected $table = 'bill_item_billing_map';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'inv_item_id', 'billing_service_id', 'active',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): ItemBillingMapFactory
    {
        return ItemBillingMapFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function map(
        string $id,
        string $tenantId,
        string $invItemId,
        string $billingServiceId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'inv_item_id' => $invItemId,
            'billing_service_id' => $billingServiceId,
            'active' => true,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function deactivate(): void
    {
        $this->active = false;
    }
}
