<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Model;

use App\Domains\BedWard\Database\Factories\RoomFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physical room within a ward (24-bed-and-ward-management-domain.md §6;
 * ADR-147 D-B, ADR-148 D-A).
 *
 * Plain Eloquent child model — NOT an AggregateRoot (ADR-148 D-A: no RoomCreated
 * event exists, room's identity is not independently event-worthy). Written
 * directly by CreateRoomHandler.
 */
class Room extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    protected static function newFactory(): RoomFactory
    {
        return RoomFactory::new();
    }

    protected $table = 'bw_rooms';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'ward_id',
        'name',
        'room_type',
        'isolation_capable',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'isolation_capable' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Ward, $this>
     */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'ward_id');
    }
}
