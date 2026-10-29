<?php

declare(strict_types=1);

namespace App\Domains\Organization\Contracts\Dto;

use App\Domains\Organization\Domain\Model\Facility;

/**
 * A facility's org structure as a readonly cross-boundary DTO
 * (docs/00-foundation/02-organization.md §15). No Eloquent leaks past the port.
 */
final readonly class FacilityStructure
{
    /**
     * @param  list<array<string, mixed>>  $departments  each: id, name, code, units[] (each: id, name, code, rooms[])
     */
    public function __construct(
        public string $facilityId,
        public string $facilityName,
        public array $departments,
    ) {}

    public static function fromModel(Facility $facility): self
    {
        return new self(
            facilityId: (string) $facility->id,
            facilityName: (string) $facility->name,
            departments: array_values($facility->departments->map(static fn ($d): array => [
                'id' => (string) $d->id,
                'name' => (string) $d->name,
                'code' => (string) $d->code,
                'units' => $d->units->map(static fn ($u): array => [
                    'id' => (string) $u->id,
                    'name' => (string) $u->name,
                    'code' => (string) $u->code,
                    'rooms' => $u->rooms->map(static fn ($r): array => [
                        'id' => (string) $r->id,
                        'name' => (string) $r->name,
                        'code' => (string) $r->code,
                    ])->values()->all(),
                ])->values()->all(),
            ])->all()),
        );
    }
}
