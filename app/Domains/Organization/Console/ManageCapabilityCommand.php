<?php

declare(strict_types=1);

namespace App\Domains\Organization\Console;

use App\Domains\Organization\Domain\Enum\CapabilityState;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\FacilityCapability;
use App\Domains\Organization\Domain\Service\CapabilityDependencyValidator;
use App\Domains\Organization\Infrastructure\Reader\CapabilityReader;
use App\Shared\Audit\AuditLogger;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 3 ops entry point for per-facility capability enablement
 * (docs/00-foundation/06-facility-levels-and-capabilities.md §11, §16, §60–§61,
 * §93). Enable validates declared dependencies (ADR-013); every change is
 * audited and flushes the capability cache.
 */
final class ManageCapabilityCommand extends Command
{
    protected $signature = 'afyanova:org:capability
        {action : enable|suspend|disable}
        {--tenant= : tenant uuid}
        {--facility= : facility uuid}
        {--capability= : capability code}
        {--reason= : reason (required for suspend)}';

    protected $description = 'Enable, suspend, or disable a facility capability.';

    public function handle(
        CapabilityReader $reader,
        CapabilityDependencyValidator $validator,
        AuditLogger $audit,
        FacilityCapabilities $cache,
    ): int {
        $tenantId = (string) $this->option('tenant');
        $facilityId = (string) $this->option('facility');
        $code = (string) $this->option('capability');
        $action = (string) $this->argument('action');

        if ($tenantId === '' || $facilityId === '' || $code === '') {
            $this->error('--tenant, --facility and --capability are required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $code, $action, $reader, $validator, $audit, $cache): int {
            if (! Facility::query()->whereKey($facilityId)->exists()) {
                $this->error("Facility {$facilityId} not found in this tenant.");

                return self::FAILURE;
            }

            if (! $reader->isRegistered($code)) {
                $this->error("Capability '{$code}' is not in the registry (org_capabilities).");

                return self::FAILURE;
            }

            if ($action === 'enable') {
                $missing = $validator->missingDependencies($code, $reader->enabledCodes($facilityId), $reader->dependencyMap());
                if ($missing !== []) {
                    $this->error("Cannot enable {$code}: missing dependencies ".implode(', ', $missing));

                    return self::FAILURE;
                }
            }

            [$state, $reason] = match ($action) {
                'enable' => [CapabilityState::Enabled, null],
                'suspend' => [CapabilityState::Suspended, (string) $this->option('reason')],
                'disable' => [CapabilityState::Disabled, null],
                default => [null, null],
            };

            if ($state === null) {
                $this->error('action must be enable|suspend|disable');

                return self::FAILURE;
            }

            if ($action === 'suspend' && $reason === '') {
                $this->error('--reason is required for suspend.');

                return self::FAILURE;
            }

            $cap = DB::transaction(function () use ($tenantId, $facilityId, $code, $state, $reason, $action, $audit): FacilityCapability {
                $cap = FacilityCapability::query()->updateOrCreate(
                    ['facility_id' => $facilityId, 'capability_code' => $code],
                    [
                        'tenant_id' => $tenantId,
                        'state' => $state,
                        'enabled_at' => $state === CapabilityState::Enabled ? now() : null,
                        'suspended_reason' => $reason ?: null,
                    ],
                );

                $audit->recordFor(
                    tenantId: $tenantId,
                    action: 'organization.capability.'.$action,
                    facilityId: $facilityId,
                    targetType: 'facility',
                    targetId: $facilityId,
                    reason: $reason ?: null,
                    metadata: ['capability' => $code, 'state' => $state->value],
                );

                return $cap;
            });

            $cache->flush($facilityId);

            $this->info("Facility {$facilityId}: {$code} → {$state->value} ({$cap->id})");

            return self::SUCCESS;
        });
    }
}
