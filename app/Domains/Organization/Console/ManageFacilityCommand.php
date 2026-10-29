<?php

declare(strict_types=1);

namespace App\Domains\Organization\Console;

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 3 ops entry point for facility structure (no command bus yet — PHASE 4).
 * `Console/` is an operational entry point like `Database/` seeders; direct
 * model use is permitted here.
 */
final class ManageFacilityCommand extends Command
{
    protected $signature = 'afyanova:org:facility
        {action : create|update}
        {--tenant= : tenant uuid}
        {--organization= : organization uuid (for create)}
        {--code= : facility code}
        {--name= : facility name}
        {--type=DISPENSARY : facility_type code}
        {--level=PRIMARY : care_level code}
        {--ownership=PUBLIC : ownership_type code}
        {--status=ACTIVE : ACTIVE|SUSPENDED|CLOSED}';

    protected $description = 'Create or update an organization facility.';

    public function handle(AuditLogger $audit): int
    {
        $tenantId = (string) $this->option('tenant');
        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $audit): int {
            $code = (string) $this->option('code');

            return match ($this->argument('action')) {
                'create' => $this->create($tenantId, $code, $audit),
                'update' => $this->update($tenantId, $code, $audit),
                default => tap(self::FAILURE, fn () => $this->error('action must be create|update')),
            };
        });
    }

    private function create(string $tenantId, string $code, AuditLogger $audit): int
    {
        $orgId = (string) $this->option('organization');
        if (! Organization::query()->whereKey($orgId)->exists()) {
            $this->error("Organization {$orgId} not found in this tenant.");

            return self::FAILURE;
        }

        $facility = DB::transaction(function () use ($tenantId, $orgId, $code, $audit): Facility {
            $facility = Facility::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                [
                    'organization_id' => $orgId,
                    'name' => (string) $this->option('name'),
                    'facility_type' => (string) $this->option('type'),
                    'care_level' => (string) $this->option('level'),
                    'ownership_type' => (string) $this->option('ownership'),
                    'status' => (string) $this->option('status'),
                ],
            );

            if ($facility->wasRecentlyCreated) {
                $audit->recordFor(
                    tenantId: $tenantId,
                    action: 'organization.facility.created',
                    facilityId: (string) $facility->getKey(),
                    metadata: ['code' => $facility->code, 'status' => $facility->status],
                    targetType: 'facility',
                    targetId: (string) $facility->getKey(),
                );
            }

            return $facility;
        });

        $this->info("Facility {$facility->code} = {$facility->id}");

        return self::SUCCESS;
    }

    private function update(string $tenantId, string $code, AuditLogger $audit): int
    {
        $facility = DB::transaction(function () use ($tenantId, $code, $audit): ?Facility {
            $facility = Facility::query()->where('code', $code)->first();
            if ($facility === null) {
                return null;
            }

            $facility->fill(array_filter([
                'name' => $this->option('name'),
                'status' => $this->option('status'),
            ], static fn ($v) => $v !== null))->save();

            if ($facility->wasChanged()) {
                $audit->recordFor(
                    tenantId: $tenantId,
                    action: 'organization.facility.updated',
                    facilityId: (string) $facility->getKey(),
                    metadata: ['code' => $facility->code, 'status' => $facility->status],
                    targetType: 'facility',
                    targetId: (string) $facility->getKey(),
                );
            }

            return $facility;
        });
        if ($facility === null) {
            $this->error("Facility {$code} not found.");

            return self::FAILURE;
        }

        $this->info("Facility {$code} updated.");

        return self::SUCCESS;
    }
}
