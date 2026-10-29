<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-115 Daily sequence counter per queue and facility.
 *
 * @authority coordination (PLATFORM SERVICE)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $queue_code
 * @property string $counter_date
 * @property int $last_number
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TicketCounter extends Model
{
    use HasUuids;

    protected $table = 'qms_ticket_counters';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'queue_code',
        'counter_date',
        'last_number',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'counter_date' => 'date',
        'last_number' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
