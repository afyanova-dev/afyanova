<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Line item on a payer remittance advice settling a claim
 * (21-insurance-and-payer-domain.md §98–§104; ADR-205).
 *
 * Owned by the `Remittance` aggregate root.
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $remittance_id
 * @property int $line_number
 * @property string|null $claim_id
 * @property string|null $adjudication_id
 * @property string|null $external_claim_reference
 * @property string $claimed_amount
 * @property string $paid_amount
 * @property string $contractual_adjustment
 * @property string $patient_responsibility
 * @property string $denied_amount
 * @property string $status
 * @property string|null $discrepancy_reason
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class RemittanceLine extends Model
{
    protected $table = 'ins_remittance_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'remittance_id',
        'line_number',
        'claim_id',
        'adjudication_id',
        'external_claim_reference',
        'claimed_amount',
        'paid_amount',
        'contractual_adjustment',
        'patient_responsibility',
        'denied_amount',
        'status',
        'discrepancy_reason',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'claimed_amount' => 'string',
            'paid_amount' => 'string',
            'contractual_adjustment' => 'string',
            'patient_responsibility' => 'string',
            'denied_amount' => 'string',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Remittance, $this>
     */
    public function remittance(): BelongsTo
    {
        return $this->belongsTo(Remittance::class, 'remittance_id');
    }
}
