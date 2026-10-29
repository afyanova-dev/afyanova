<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Model;

use App\Domains\BedWard\Contracts\Event\BedCreated;
use App\Domains\BedWard\Contracts\Event\BedDecommissioned;
use App\Domains\BedWard\Contracts\Event\BedStatusChanged;
use App\Domains\BedWard\Database\Factories\BedFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Bed aggregate root (24-bed-and-ward-management-domain.md §6;
 * ADR-147 D-A/D-B, ADR-148 D-A/D-B).
 *
 * Emits BedCreated, BedStatusChanged, BedDecommissioned.
 *
 * @domain BedWard
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
 * @property string $ward_id
 * @property string|null $room_id
 * @property string $label
 * @property string $bed_class
 * @property array<string, mixed>|list<string> $features
 * @property string $status
 * @property int $version
 */
final class Bed extends AggregateRoot
{
    /** @use HasFactory<BedFactory> */
    use HasFactory;

    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_RESERVED = 'RESERVED';

    public const STATUS_OCCUPIED = 'OCCUPIED';

    public const STATUS_CLEANING = 'CLEANING';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const STATUS_MAINTENANCE = 'MAINTENANCE';

    public const STATUS_DECOMMISSIONED = 'DECOMMISSIONED';

    protected $table = 'bw_beds';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'ward_id',
        'room_id',
        'label',
        'bed_class',
        'features',
        'status',
        'version',
    ];

    protected static function newFactory(): BedFactory
    {
        return BedFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * @param  list<string>|array<string, mixed>  $features
     */
    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $wardId,
        ?string $roomId,
        string $label,
        string $bedClass,
        array $features = [],
    ): self {
        $bed = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'ward_id' => $wardId,
            'room_id' => $roomId,
            'label' => $label,
            'bed_class' => $bedClass,
            'features' => $features,
            'status' => self::STATUS_AVAILABLE,
        ]);

        $now = CarbonImmutable::now()->toIso8601String();

        $bed->recordThat(new BedCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            bedId: $id,
            wardId: $wardId,
            roomId: $roomId,
            bedClass: $bedClass,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $bed;
    }

    /**
     * @param  list<string>|array<string, mixed>  $features
     */
    public function updateDetails(string $label, string $bedClass, array $features): void
    {
        $this->label = $label;
        $this->bed_class = $bedClass;
        $this->features = $features;
    }

    public function transitionStatus(string $targetStatus): void
    {
        $fromStatus = (string) $this->status;
        $this->status = $targetStatus;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new BedStatusChanged(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            bedId: (string) $this->id,
            fromStatus: $fromStatus,
            toStatus: $targetStatus,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function decommission(): void
    {
        $this->status = self::STATUS_DECOMMISSIONED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new BedDecommissioned(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            bedId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
