<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Console;

use App\Domains\Laboratory\Domain\Exception\LabPanelAlreadyExists;
use App\Domains\Laboratory\Domain\LabPanelRepository;
use App\Domains\Laboratory\Domain\LabTestDefinitionRepository;
use App\Domains\Laboratory\Domain\Model\LabPanel;
use App\Domains\Laboratory\Domain\Model\LabPanelMember;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * PHASE 8 (increment Lab.4) ops entry point for laboratory panel authoring
 * (14-laboratory-domain.md §14–§15, §192; ADR-162 D-C — the CP.1
 * `laboratory:define-test` precedent).
 *
 * CONSOLE-ONLY — no HTTP command, no permission key. `--tests` is a
 * comma-separated list of test CODES (resolved to `lab_test_definitions` in the
 * same tenant). `update` with `--tests` replaces the member set. Every change
 * is audited (`laboratory.panel_defined` / `.panel_updated`).
 */
final class DefineLabPanelCommand extends Command
{
    protected $signature = 'laboratory:define-panel
        {action : define|update}
        {--tenant= : tenant uuid}
        {--id= : panel uuid (required for update)}
        {--code= : panel code (required for define)}
        {--name= : panel name (required for define)}
        {--category= : category}
        {--description= : description}
        {--tests= : comma-separated test codes (required for define; replaces the set on update)}
        {--active= : 1|0 — update only}';

    protected $description = 'Define or update a laboratory panel (ADR-162 D-C).';

    public function handle(LabPanelRepository $panels, LabTestDefinitionRepository $definitions, AuditLogger $audit): int
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

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $action, $panels, $definitions, $audit): int {
            return $action === 'define'
                ? $this->define($tenantId, $panels, $definitions, $audit)
                : $this->update($tenantId, $panels, $definitions, $audit);
        });
    }

    private function define(string $tenantId, LabPanelRepository $panels, LabTestDefinitionRepository $definitions, AuditLogger $audit): int
    {
        $code = trim((string) $this->option('code'));
        $name = trim((string) $this->option('name'));

        if ($code === '' || $name === '') {
            $this->error('--code and --name are required for define.');

            return self::FAILURE;
        }

        if ($panels->findActiveByCode($tenantId, $code) !== null) {
            throw LabPanelAlreadyExists::forCode($tenantId, $code);
        }

        $definitionIds = $this->resolveTestCodes($tenantId, $definitions);
        if ($definitionIds === []) {
            $this->error('--tests must name at least one known test code.');

            return self::FAILURE;
        }

        $id = (string) Str::uuid7();
        $panel = LabPanel::define(
            id: $id,
            tenantId: $tenantId,
            code: $code,
            name: $name,
            category: $this->nullableOption('category'),
            description: $this->nullableOption('description'),
        );
        $panels->save($panel);

        $this->replaceMembers($tenantId, $id, $definitionIds);

        $audit->record(action: 'laboratory.panel_defined', targetType: 'lab_panel', targetId: $id, metadata: ['code' => $code, 'tests' => count($definitionIds)]);

        $this->info("Defined laboratory panel [{$code}] ({$id}) with ".count($definitionIds).' tests, v1');

        return self::SUCCESS;
    }

    private function update(string $tenantId, LabPanelRepository $panels, LabTestDefinitionRepository $definitions, AuditLogger $audit): int
    {
        $id = (string) $this->option('id');

        if ($id === '') {
            $this->error('--id is required for update.');

            return self::FAILURE;
        }

        $panel = $panels->getByIdForUpdate($id);
        $active = $this->nullableOption('active');
        $panel->revise(
            name: $this->nullableOption('name'),
            category: $this->nullableOption('category'),
            description: $this->nullableOption('description'),
            active: $active !== null ? $active !== '0' : null,
        );
        $panels->save($panel);

        if ($this->nullableOption('tests') !== null) {
            $this->replaceMembers($tenantId, $id, $this->resolveTestCodes($tenantId, $definitions));
        }

        $audit->record(action: 'laboratory.panel_updated', targetType: 'lab_panel', targetId: $id, metadata: ['version' => $panel->version]);

        $this->info("Updated laboratory panel [{$id}] -> v{$panel->version}");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveTestCodes(string $tenantId, LabTestDefinitionRepository $definitions): array
    {
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('tests')))));

        $ids = [];
        foreach ($codes as $code) {
            $definition = $definitions->findActiveByCode($tenantId, $code);
            if ($definition === null) {
                $this->warn("Skipping unknown test code [{$code}].");

                continue;
            }
            $ids[] = (string) $definition->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>  $definitionIds
     */
    private function replaceMembers(string $tenantId, string $panelId, array $definitionIds): void
    {
        LabPanelMember::query()->where('panel_id', $panelId)->delete();

        $sequence = 1;
        foreach ($definitionIds as $definitionId) {
            LabPanelMember::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'panel_id' => $panelId,
                'test_definition_id' => $definitionId,
                'sequence' => $sequence++,
            ]);
        }
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
