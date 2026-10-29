<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Console;

use App\Domains\Radiology\Domain\Exception\ImagingServiceAlreadyExists;
use App\Domains\Radiology\Domain\ImagingModalityRepository;
use App\Domains\Radiology\Domain\ImagingServiceRepository;
use App\Domains\Radiology\Domain\Model\ImagingService;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * PHASE 8 (increment Rad.1) ops entry point for imaging service catalog
 * authoring (15-radiology-domain.md §12, §222; ADR-165 D-D — the CP.1 / Lab.1
 * catalog-console precedent).
 *
 * CONSOLE-ONLY in Rad.1 — no HTTP command, no permission key. `--modality`
 * takes the parent modality's `code` (resolved to its id here). Every change is
 * audited (`radiology.imaging_service_defined` / `.imaging_service_updated`).
 */
final class DefineImagingServiceCommand extends Command
{
    protected $signature = 'radiology:define-imaging-service
        {action : define|update}
        {--tenant= : tenant uuid}
        {--id= : service uuid (required for update)}
        {--code= : service code, e.g. XR-CHEST (required for define)}
        {--name= : service name (required for define)}
        {--modality= : parent modality code (required for define)}
        {--body-site= : body site, e.g. CHEST}
        {--laterality= : default laterality, e.g. NOT_APPLICABLE}
        {--contrast= : NONE|OPTIONAL|REQUIRED}
        {--preparation= : preparation instructions (free text)}
        {--duration= : duration in minutes}
        {--tat= : turnaround target in minutes}
        {--capability= : required facility capability code}
        {--referral-only= : 1|0}
        {--availability= : AVAILABLE|DISABLED|...}
        {--active= : 1|0 — update only}';

    protected $description = 'Define or update an imaging service catalog entry (ADR-165 D-D).';

    public function handle(
        ImagingServiceRepository $services,
        ImagingModalityRepository $modalities,
        AuditLogger $audit,
    ): int {
        $tenantId = (string) $this->option('tenant');
        $action = (string) $this->argument('action');

        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        if (! in_array($action, ['define', 'update'], true)) {
            $this->error('action must be define|update');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $action, $services, $modalities, $audit): int {
            if ($action === 'define') {
                return $this->define($tenantId, $services, $modalities, $audit);
            }

            return $this->update($services, $modalities, $audit);
        });
    }

    private function define(
        string $tenantId,
        ImagingServiceRepository $services,
        ImagingModalityRepository $modalities,
        AuditLogger $audit,
    ): int {
        $code = (string) $this->option('code');
        $name = (string) $this->option('name');
        $modalityCode = (string) $this->option('modality');

        if ($code === '' || $name === '' || $modalityCode === '') {
            $this->error('--code, --name and --modality are required for define.');

            return self::FAILURE;
        }

        $modality = $modalities->findActiveByCode($tenantId, strtoupper(trim($modalityCode)));
        if ($modality === null) {
            $this->error("No active imaging modality with code [{$modalityCode}] in this tenant.");

            return self::FAILURE;
        }

        if ($services->findActiveByCode($tenantId, strtoupper(trim($code))) !== null) {
            throw ImagingServiceAlreadyExists::forCode($tenantId, strtoupper(trim($code)));
        }

        $id = (string) Str::uuid7();

        $service = ImagingService::define(
            id: $id,
            tenantId: $tenantId,
            code: $code,
            name: $name,
            modalityId: (string) $modality->id,
            bodySite: $this->nullableOption('body-site'),
            defaultLaterality: $this->nullableOption('laterality'),
            contrastRequirement: $this->nullableOption('contrast') ?? ImagingService::CONTRAST_NONE,
            preparationText: $this->nullableOption('preparation'),
            durationMinutes: $this->intOption('duration'),
            turnaroundTargetMinutes: $this->intOption('tat'),
            requiredCapabilityCode: $this->nullableOption('capability'),
            referralOnly: $this->boolOption('referral-only') ?? false,
            availabilityStatus: $this->nullableOption('availability') ?? ImagingService::AVAILABILITY_AVAILABLE,
        );

        $services->save($service);

        $audit->record(
            action: 'radiology.imaging_service_defined',
            targetType: 'imaging_service',
            targetId: $id,
            metadata: ['code' => $service->code, 'modality_code' => $modality->code],
        );

        $this->info("Defined imaging service [{$service->code}] ({$id}) v{$service->version}");

        return self::SUCCESS;
    }

    private function update(
        ImagingServiceRepository $services,
        ImagingModalityRepository $modalities,
        AuditLogger $audit,
    ): int {
        $id = (string) $this->option('id');

        if ($id === '') {
            $this->error('--id is required for update.');

            return self::FAILURE;
        }

        $service = $services->getByIdForUpdate($id);

        $modalityId = null;
        $modalityCode = $this->nullableOption('modality');
        if ($modalityCode !== null) {
            $modality = $modalities->findActiveByCode((string) $service->tenant_id, strtoupper(trim($modalityCode)));
            if ($modality === null) {
                $this->error("No active imaging modality with code [{$modalityCode}] in this tenant.");

                return self::FAILURE;
            }
            $modalityId = (string) $modality->id;
        }

        $active = $this->nullableOption('active');

        $service->revise(
            name: $this->nullableOption('name'),
            modalityId: $modalityId,
            bodySite: $this->nullableOption('body-site'),
            defaultLaterality: $this->nullableOption('laterality'),
            contrastRequirement: $this->nullableOption('contrast'),
            preparationText: $this->nullableOption('preparation'),
            durationMinutes: $this->intOption('duration'),
            turnaroundTargetMinutes: $this->intOption('tat'),
            requiredCapabilityCode: $this->nullableOption('capability'),
            referralOnly: $this->boolOption('referral-only'),
            availabilityStatus: $this->nullableOption('availability'),
            active: $active !== null ? $active !== '0' : null,
        );

        $services->save($service);

        $audit->record(
            action: 'radiology.imaging_service_updated',
            targetType: 'imaging_service',
            targetId: $id,
            metadata: ['version' => $service->version],
        );

        $this->info("Updated imaging service [{$id}] -> v{$service->version}");

        return self::SUCCESS;
    }

    private function nullableOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->nullableOption($name);

        return $value === null ? null : (int) $value;
    }

    private function boolOption(string $name): ?bool
    {
        $value = $this->nullableOption($name);

        return $value === null ? null : $value !== '0';
    }
}
