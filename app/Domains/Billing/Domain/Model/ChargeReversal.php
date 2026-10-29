<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\ChargeReversalFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The error-correction inverse of a POSTED {@see Charge} — from a
 * `pharmacy.MedicationDispensingReversed` event (20-billing-and-payments-domain.md
 * §113 / §135; ADR-189 D-D, sponsor ruling C8).
 *
 * Append-only, one per Charge (INV-2), FULL reversal only. Records no event —
 * the `Charge::markReversed()` records `ChargeReversed`.
 *
 * @domain      Billing
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $charge_id
 * @property string $financial_account_id
 * @property string $source_reversal_event_id
 * @property string $dispensing_id
 * @property string $amount
 * @property string $currency
 * @property string $reason_code
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class ChargeReversal extends AggregateRoot
{
    /** @use HasFactory<ChargeReversalFactory> */
    use HasFactory;

    protected $table = 'bill_charge_reversals';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'charge_id', 'financial_account_id', 'source_reversal_event_id', 'dispensing_id',
        'amount', 'currency', 'reason_code',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): ChargeReversalFactory
    {
        return ChargeReversalFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string  $amount  scale-2 — the full charge amount
     */
    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $chargeId,
        string $financialAccountId,
        string $sourceReversalEventId,
        string $dispensingId,
        string $amount,
        string $currency,
        string $reasonCode,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'charge_id' => $chargeId,
            'financial_account_id' => $financialAccountId,
            'source_reversal_event_id' => $sourceReversalEventId,
            'dispensing_id' => $dispensingId,
            'amount' => $amount,
            'currency' => $currency,
            'reason_code' => $reasonCode,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }
}
