<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Model;

use App\Domains\Scheduling\Contracts\Event\SchedulableResourceRegistered;
use App\Domains\Scheduling\Domain\ValueObject\ResourceType;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Schedulable resource projection aggregate root (ADR-111; 22-scheduling-and-appointments-domain.md §28).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property ResourceType $resource_type
 * @property string $resource_ref
 * @property string $name
 * @property bool $is_active
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class SchedulableResource extends AggregateRoot
{
    protected $table = 'sch_schedulable_resources';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'resource_type',
        'resource_ref',
        'name',
        'is_active',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'resource_type' => ResourceType::class,
        'is_active' => 'boolean',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function register(
        string $tenantId,
        string $facilityId,
        ResourceType|string $resourceType,
        string $resourceRef,
        string $name,
        bool $isActive = true,
        ?string $resourceId = null,
    ): self {
        $id = $resourceId ?? (string) Str::uuid();
        $type = is_string($resourceType) ? ResourceType::from($resourceType) : $resourceType;

        $resource = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'resource_type' => $type,
            'resource_ref' => $resourceRef,
            'name' => trim($name),
            'is_active' => $isActive,
            'version' => 0,
        ]);

        $resource->id = $id;

        $resource->recordThat(new SchedulableResourceRegistered(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            resourceId: $id,
            facilityId: $facilityId,
            resourceType: $type->value,
            resourceRef: $resourceRef,
            name: $resource->name,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $resource;
    }
}
