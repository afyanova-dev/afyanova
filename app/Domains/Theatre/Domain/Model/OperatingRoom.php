<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Database\Factories\OperatingRoomFactory;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * OperatingRoom aggregate root — the operational-resource view of an
 * Organization room (17-theatre-domain.md §17; ADR-150 D-C).
 *
 * AggregateRoot for versioning/tenancy (D-C), but emits NO domain events in
 * T.1 — D-K's 9-event list is exclusively SurgicalCase; no T.1 consumer
 * reads OR-state changes as events (OR-board queries read current state
 * directly). Events may be added in T.2+ behind a concrete consumer (IC §5.2).
 *
 * @domain Theatre
 *
 * @authority authoritative
 *
 * @tenant required
 *
 * @facility required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $org_room_id
 * @property string $label
 * @property string $status
 * @property int $version
 */
final class OperatingRoom extends AggregateRoot
{
    /** @use HasFactory<OperatingRoomFactory> */
    use HasFactory;

    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_RESERVED = 'RESERVED';

    public const STATUS_IN_USE = 'IN_USE';

    public const STATUS_CLEANING = 'CLEANING';

    public const STATUS_MAINTENANCE = 'MAINTENANCE';

    public const STATUS_CLOSED = 'CLOSED';

    protected $table = 'thr_operating_rooms';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'org_room_id',
        'label',
        'status',
        'version',
    ];

    protected static function newFactory(): OperatingRoomFactory
    {
        return OperatingRoomFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $orgRoomId,
        string $label,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'org_room_id' => $orgRoomId,
            'label' => $label,
            'status' => self::STATUS_AVAILABLE,
        ]);
    }

    public function transitionStatus(string $targetStatus): void
    {
        $this->status = $targetStatus;
    }
}
