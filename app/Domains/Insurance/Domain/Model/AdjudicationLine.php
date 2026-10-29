<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adjudicated line item evaluated by a payer for a claim line
 * (21-insurance-and-payer-domain.md §84–§87; ADR-205).
 *
 * Owned by the `Adjudication` aggregate root.
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $adjudication_id
 * @property string $claim_line_id
 * @property int $line_number
 * @property string $status
 * @property string $claimed_amount
 * @property string $approved_amount
 * @property string $denied_amount
 * @property string $patient_responsibility
 * @property string $contractual_adjustment
 * @property string|null $denial_reason_code
 * @property string|null $remarks
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class AdjudicationLine extends Model
{
    protected $table = 'ins_adjudication_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'adjudication_id',
        'claim_line_id',
        'line_number',
        'status',
        'claimed_amount',
        'approved_amount',
        'denied_amount',
        'patient_responsibility',
        'contractual_adjustment',
        'denial_reason_code',
        'remarks',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'claimed_amount' => 'string',
            'approved_amount' => 'string',
            'denied_amount' => 'string',
            'patient_responsibility' => 'string',
            'contractual_adjustment' => 'string',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Adjudication, $this>
     */
    public function adjudication(): BelongsTo
    {
        return $this->belongsTo(Adjudication::class, 'adjudication_id');
    }
}
