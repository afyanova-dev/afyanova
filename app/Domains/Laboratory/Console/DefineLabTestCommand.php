<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Console;

use App\Domains\Laboratory\Domain\Exception\LabTestDefinitionAlreadyExists;
use App\Domains\Laboratory\Domain\LabTestDefinitionRepository;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * PHASE 8 (increment Lab.1) ops entry point for laboratory test catalog
 * authoring (14-laboratory-domain.md §124–§125, §191; ADR-157 D-C — the CP.1
 * `clinical:define-procedure` precedent).
 *
 * `LabTestDefinition` catalog authoring is CONSOLE-ONLY in Lab.1 — there is NO
 * HTTP `DefineLabTest` bus command and NO `laboratory.catalog.manage`
 * permission key. Full approval / maker-checker / effective-dating governance is
 * deferred (working name Lab.0). Every change is audited
 * (`laboratory.test_defined` / `.test_updated` — §191 governance trail).
 */
final class DefineLabTestCommand extends Command
{
    protected $signature = 'laboratory:define-test
        {action : define|update}
        {--tenant= : tenant uuid}
        {--id= : definition uuid (required for update)}
        {--code= : test code (required for define)}
        {--name= : test name (required for define)}
        {--category= : category, e.g. HAEMATOLOGY}
        {--description= : description}
        {--specimen-type= : specimen type, e.g. WHOLE_BLOOD}
        {--container-type= : container type, e.g. EDTA_TUBE}
        {--capability= : required facility capability code}
        {--availability= : AVAILABLE|TEMPORARILY_UNAVAILABLE|REFERRAL_ONLY|DISABLED}
        {--tat= : turnaround target in minutes}
        {--active= : 1|0 — update only}';

    protected $description = 'Define or update a laboratory test catalog entry (ADR-157 D-C).';

    public function handle(LabTestDefinitionRepository $definitions, AuditLogger $audit): int
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

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $action, $definitions, $audit): int {
            if ($action === 'define') {
                return $this->define($tenantId, $definitions, $audit);
            }

            return $this->update($definitions, $audit);
        });
    }

    private function define(string $tenantId, LabTestDefinitionRepository $definitions, AuditLogger $audit): int
    {
        $code = (string) $this->option('code');
        $name = (string) $this->option('name');

        if ($code === '' || $name === '') {
            $this->error('--code and --name are required for define.');

            return self::FAILURE;
        }

        if ($definitions->findActiveByCode($tenantId, trim($code)) !== null) {
            throw LabTestDefinitionAlreadyExists::forCode($tenantId, trim($code));
        }

        $id = (string) Str::uuid7();

        $definition = LabTestDefinition::define(
            id: $id,
            tenantId: $tenantId,
            code: $code,
            name: $name,
            category: $this->nullableOption('category'),
            description: $this->nullableOption('description'),
            specimenType: $this->nullableOption('specimen-type'),
            containerType: $this->nullableOption('container-type'),
            parameters: null,
            requiredCapabilityCode: $this->nullableOption('capability'),
            availabilityStatus: $this->nullableOption('availability') ?? LabTestDefinition::AVAILABILITY_AVAILABLE,
            turnaroundTargetMinutes: $this->intOption('tat'),
        );

        $definitions->save($definition);

        $audit->record(
            action: 'laboratory.test_defined',
            targetType: 'lab_test_definition',
            targetId: $id,
            metadata: ['code' => trim($code)],
        );

        $this->info("Defined laboratory test [{$definition->code}] ({$id}) v{$definition->version}");

        return self::SUCCESS;
    }

    private function update(LabTestDefinitionRepository $definitions, AuditLogger $audit): int
    {
        $id = (string) $this->option('id');

        if ($id === '') {
            $this->error('--id is required for update.');

            return self::FAILURE;
        }

        $definition = $definitions->getByIdForUpdate($id);

        $active = $this->nullableOption('active');

        $definition->revise(
            name: $this->nullableOption('name'),
            category: $this->nullableOption('category'),
            description: $this->nullableOption('description'),
            specimenType: $this->nullableOption('specimen-type'),
            containerType: $this->nullableOption('container-type'),
            parameters: null,
            requiredCapabilityCode: $this->nullableOption('capability'),
            availabilityStatus: $this->nullableOption('availability'),
            turnaroundTargetMinutes: $this->intOption('tat'),
            active: $active !== null ? $active !== '0' : null,
        );

        $definitions->save($definition);

        $audit->record(
            action: 'laboratory.test_updated',
            targetType: 'lab_test_definition',
            targetId: $id,
            metadata: ['version' => $definition->version],
        );

        $this->info("Updated laboratory test definition [{$id}] -> v{$definition->version}");

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
}
