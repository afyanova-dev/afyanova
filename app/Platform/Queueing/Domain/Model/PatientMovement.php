<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Operational Patient Movement tracking (23-queueing-and-patient-flow.md §18, §19, §310; ADR-130 B).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $encounter_id
 * @property string $patient_id
 * @property string|null $from_area
 * @property string $to_area
 * @property string $status
 * @property string|null $queue_entry_id
 * @property string|null $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PatientMovement extends Model
{
    use HasUuids;

    protected $table = 'qms_patient_movements';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'encounter_id',
        'patient_id',
        'from_area',
        'to_area',
        'status',
        'queue_entry_id',
        'actor_id',
        'reason',
        'occurred_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
