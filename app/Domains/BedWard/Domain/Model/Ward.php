<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Model;

use App\Domains\BedWard\Contracts\Event\WardCreated;
use App\Domains\BedWard\Contracts\Event\WardDecommissioned;
use App\Domains\BedWard\Database\Factories\WardFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Inpatient Ward aggregate root (24-bed-and-ward-management-domain.md §6;
 * ADR-147 D-A/D-B, ADR-148 D-A/D-B).
 *
 * Emits WardCreated and WardDecommissioned. Status lifecycle: ACTIVE, SUSPENDED, DECOMMISSIONED.
 *
 * @domain BedWard
 *
 * @authority authoritative
 *
 * @tenant required
 *
 * @facility required
 *
 * @property int $capacity
 */
final class Ward extends AggregateRoot
{
    /** @use HasFactory<WardFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_DECOMMISSIONED = 'DECOMMISSIONED';

    protected $table = 'bw_wards';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'ward_type',
        'capability_link',
        'capacity',
        'status',
        'version',
    ];

    protected static function newFactory(): WardFactory
    {
        return WardFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $code,
        string $name,
        string $wardType,
        int $capacity,
        ?string $capabilityLink = null,
    ): self {
        $ward = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => $code,
            'name' => $name,
            'ward_type' => $wardType,
            'capability_link' => $capabilityLink,
            'capacity' => $capacity,
            'status' => self::STATUS_ACTIVE,
        ]);

        $now = CarbonImmutable::now()->toIso8601String();

        $ward->recordThat(new WardCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            wardId: $id,
            wardType: $wardType,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $ward;
    }

    /**
     * @param  int<0, max>  $capacity
     */
    public function updateDetails(string $name, string $wardType, int $capacity): void
    {
        $this->name = $name;
        $this->ward_type = $wardType;
        $this->capacity = max(0, $capacity);
    }

    public function decommission(): void
    {
        $this->status = self::STATUS_DECOMMISSIONED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new WardDecommissioned(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            wardId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
