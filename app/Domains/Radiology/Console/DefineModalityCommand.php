<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Console;

use App\Domains\Radiology\Domain\Exception\ImagingModalityAlreadyExists;
use App\Domains\Radiology\Domain\ImagingModalityRepository;
use App\Domains\Radiology\Domain\Model\ImagingModality;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * PHASE 8 (increment Rad.1) ops entry point for imaging modality catalog
 * authoring (15-radiology-domain.md §13, §222; ADR-165 D-C — the CP.1
 * `clinical:define-procedure` / Lab.1 `laboratory:define-test` precedent).
 *
 * `ImagingModality` catalog authoring is CONSOLE-ONLY in Rad.1 — there is NO
 * HTTP command and NO `radiology.catalog.manage` permission key. Full approval
 * / maker-checker / effective-dating governance is deferred (working name
 * "Rad.0"). Every change is audited (`radiology.modality_defined` /
 * `.modality_updated`).
 */
final class DefineModalityCommand extends Command
{
    protected $signature = 'radiology:define-modality
        {action : define|update}
        {--tenant= : tenant uuid}
        {--id= : modality uuid (required for update)}
        {--code= : modality code, e.g. CT (required for define)}
        {--name= : modality name (required for define)}
        {--category= : category}
        {--description= : description}
        {--capability= : required facility capability code}
        {--status= : ACTIVE|SUSPENDED}
        {--active= : 1|0 — update only}';

    protected $description = 'Define or update an imaging modality catalog entry (ADR-165 D-C).';

    public function handle(ImagingModalityRepository $modalities, AuditLogger $audit): int
    {
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

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $action, $modalities, $audit): int {
            if ($action === 'define') {
                return $this->define($tenantId, $modalities, $audit);
            }

            return $this->update($modalities, $audit);
        });
    }

    private function define(string $tenantId, ImagingModalityRepository $modalities, AuditLogger $audit): int
    {
        $code = (string) $this->option('code');
        $name = (string) $this->option('name');

        if ($code === '' || $name === '') {
            $this->error('--code and --name are required for define.');

            return self::FAILURE;
        }

        if ($modalities->findActiveByCode($tenantId, strtoupper(trim($code))) !== null) {
            throw ImagingModalityAlreadyExists::forCode($tenantId, strtoupper(trim($code)));
        }

        $id = (string) Str::uuid7();

        $modality = ImagingModality::define(
            id: $id,
            tenantId: $tenantId,
            code: $code,
            name: $name,
            category: $this->nullableOption('category'),
            description: $this->nullableOption('description'),
            requiredCapabilityCode: $this->nullableOption('capability'),
            operationalStatus: $this->nullableOption('status') ?? ImagingModality::STATUS_ACTIVE,
        );

        $modalities->save($modality);

        $audit->record(
            action: 'radiology.modality_defined',
            targetType: 'imaging_modality',
            targetId: $id,
            metadata: ['code' => $modality->code],
        );

        $this->info("Defined imaging modality [{$modality->code}] ({$id}) v{$modality->version}");

        return self::SUCCESS;
    }

    private function update(ImagingModalityRepository $modalities, AuditLogger $audit): int
    {
        $id = (string) $this->option('id');

        if ($id === '') {
            $this->error('--id is required for update.');

            return self::FAILURE;
        }

        $modality = $modalities->getByIdForUpdate($id);

        $active = $this->nullableOption('active');

        $modality->revise(
            name: $this->nullableOption('name'),
            category: $this->nullableOption('category'),
            description: $this->nullableOption('description'),
            requiredCapabilityCode: $this->nullableOption('capability'),
            operationalStatus: $this->nullableOption('status'),
            active: $active !== null ? $active !== '0' : null,
        );

        $modalities->save($modality);

        $audit->record(
            action: 'radiology.modality_updated',
            targetType: 'imaging_modality',
            targetId: $id,
            metadata: ['version' => $modality->version],
        );

        $this->info("Updated imaging modality [{$id}] -> v{$modality->version}");

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
}
