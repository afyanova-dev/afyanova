<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of a method supported by a facility instrument.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $instrument_id
 * @property int $instrument_version
 * @property string $method_id
 * @property int $method_version
 * @property string $method_code_snapshot
 * @property string $method_name_snapshot
 */
final class LabFacilityInstrumentMethod extends AggregateRoot
{
    protected $table = 'lab_facility_instrument_methods';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'instrument_id',
        'instrument_version',
        'method_id',
        'method_version',
        'method_code_snapshot',
        'method_name_snapshot',
    ];

    /**
     * @return BelongsTo<LabFacilityInstrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(LabFacilityInstrument::class, 'instrument_id');
    }

    /**
     * @return BelongsTo<LabTestMethod, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(LabTestMethod::class, 'method_id');
    }
}
