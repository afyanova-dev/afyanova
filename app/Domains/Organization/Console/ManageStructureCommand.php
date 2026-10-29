<?php

declare(strict_types=1);

namespace App\Domains\Organization\Console;

use App\Domains\Organization\Domain\Model\Department;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Room;
use App\Domains\Organization\Domain\Model\Unit;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 3 ops entry point for the `Department → Unit → Room` structure inside a
 * facility (docs/00-foundation/02-organization.md §11–§15, ADR-007).
 */
final class ManageStructureCommand extends Command
{
    protected $signature = 'afyanova:org:structure
        {kind : department|unit|room}
        {--tenant= : tenant uuid}
        {--facility= : facility uuid}
        {--department= : department uuid (for unit)}
        {--unit= : unit uuid (for room, optional)}
        {--code= : structure code}
        {--name= : structure name}
        {--type= : unit_type / room_type}';

    protected $description = 'Create a department, unit, or room within a facility.';

    public function handle(AuditLogger $audit): int
    {
        $tenantId = (string) $this->option('tenant');
        $facilityId = (string) $this->option('facility');
        if ($tenantId === '' || $facilityId === '') {
            $this->error('--tenant and --facility are required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $audit): int {
            if (! Facility::query()->whereKey($facilityId)->exists()) {
                $this->error("Facility {$facilityId} not found in this tenant.");

                return self::FAILURE;
            }

            $code = (string) $this->option('code');
            $name = (string) $this->option('name');

            $kind = (string) $this->argument('kind');
            $row = DB::transaction(function () use ($kind, $tenantId, $facilityId, $code, $name, $audit): Department|Unit|Room|null {
                $row = match ($kind) {
                    'department' => Department::query()->firstOrCreate(
                        ['facility_id' => $facilityId, 'code' => $code],
                        ['tenant_id' => $tenantId, 'name' => $name, 'kind' => $this->option('type')],
                    ),
                    'unit' => Unit::query()->firstOrCreate(
                        ['facility_id' => $facilityId, 'code' => $code],
                        [
                            'tenant_id' => $tenantId,
                            'department_id' => (string) $this->option('department'),
                            'name' => $name,
                            'unit_type' => (string) ($this->option('type') ?: 'GENERIC'),
                        ],
                    ),
                    'room' => Room::query()->firstOrCreate(
                        ['facility_id' => $facilityId, 'code' => $code],
                        [
                            'tenant_id' => $tenantId,
                            'unit_id' => $this->option('unit') ?: null,
                            'name' => $name,
                            'room_type' => (string) ($this->option('type') ?: 'GENERIC'),
                        ],
                    ),
                    default => null,
                };

                if ($row?->wasRecentlyCreated) {
                    $audit->recordFor(
                        tenantId: $tenantId,
                        action: 'organization.structure.'.$kind.'_created',
                        facilityId: $facilityId,
                        metadata: ['code' => $code],
                        targetType: $kind,
                        targetId: (string) $row->getKey(),
                    );
                }

                return $row;
            });

            if ($row === null) {
                $this->error('kind must be department|unit|room');

                return self::FAILURE;
            }

            $this->info("{$this->argument('kind')} {$code} = {$row->id}");

            return self::SUCCESS;
        });
    }
}
