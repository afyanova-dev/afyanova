<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of one covered, insured billing charge on a claim
 * (21-insurance-and-payer-domain.md §78; Invariant 7, §272; ADR-204).
 *
 * Owned by the `Claim` aggregate root.
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $claim_id
 * @property string $charge_id
 * @property string $service_category
 * @property string $service_code
 * @property string $service_description
 * @property string $service_date
 * @property string $quantity
 * @property string $unit_price
 * @property string $claimed_amount
 * @property string|null $authorization_id
 * @property string|null $diagnosis_code
 * @property int $line_number
 * @property bool $line_active
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class ClaimLine extends Model
{
    protected $table = 'ins_claim_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'claim_id',
        'charge_id',
        'service_category',
        'service_code',
        'service_description',
        'service_date',
        'quantity',
        'unit_price',
        'claimed_amount',
        'authorization_id',
        'diagnosis_code',
        'line_number',
        'line_active',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'unit_price' => 'string',
            'claimed_amount' => 'string',
            'line_number' => 'integer',
            'line_active' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Claim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    public function deactivate(): void
    {
        $this->line_active = false;
        $this->version++;
    }
}
