<?php

declare(strict_types=1);

namespace App\Domains\Organization\Infrastructure\Writer;

use App\Domains\Organization\Contracts\Dto\InitialTenantStructure;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InitialTenantStructureWriter
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(InitialTenantStructure $structure): void
    {
        DB::transaction(function () use ($structure): void {
            if (Organization::query()->exists() || Facility::query()->exists()) {
                throw new RuntimeException('Initial structure provisioning is only available before any organization or facility exists.');
            }

            $referencesExist = DB::table('org_facility_types')
                ->where('code', $structure->facilityType)
                ->where('active', true)
                ->exists()
                && DB::table('org_care_levels')
                    ->where('code', $structure->careLevel)
                    ->where('active', true)
                    ->exists()
                && DB::table('org_ownership_types')->where('code', $structure->ownershipType)->exists();
            if (! $referencesExist) {
                throw new RuntimeException('The selected facility type, care level, or ownership type is unavailable.');
            }

            $organization = Organization::query()->create([
                'name' => $structure->organizationName,
                'code' => $structure->organizationCode,
                'status' => 'ACTIVE',
            ]);
            $this->audit->record(
                action: 'organization.organization.created',
                actorId: $structure->actorId,
                targetType: 'organization',
                targetId: (string) $organization->getKey(),
                reason: 'Created as part of initial platform tenant setup.',
                metadata: ['code' => $organization->code],
            );

            $facility = Facility::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => $structure->facilityName,
                'code' => $structure->facilityCode,
                'facility_type' => $structure->facilityType,
                'care_level' => $structure->careLevel,
                'ownership_type' => $structure->ownershipType,
                'status' => 'ACTIVE',
            ]);
            $this->audit->record(
                action: 'organization.facility.created',
                actorId: $structure->actorId,
                targetType: 'facility',
                targetId: (string) $facility->getKey(),
                reason: 'Created as part of initial platform tenant setup.',
                metadata: ['code' => $facility->code, 'status' => $facility->status->value],
            );
        });
    }
}
