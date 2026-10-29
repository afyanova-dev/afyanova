<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Console;

use App\Domains\Clinical\Domain\Model\ProcedureDefinition;
use App\Domains\Clinical\Domain\ProcedureDefinitionRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * PHASE 6 (increment CP.1) ops entry point for procedure catalog authoring
 * (13-clinical-procedures-domain.md §111; ADR-146 D-C — the
 * `ManageCapabilityCommand` precedent).
 *
 * `ProcedureDefinition` catalog authoring is CONSOLE-ONLY in CP.1 — there is NO
 * HTTP `DefineProcedure` / `UpdateProcedureDefinition` bus command and NO
 * `clinical.procedures.manage_catalog` permission key. Full approval /
 * maker-checker / effective-dating governance is deferred (working name CP.0).
 * Every change is audited (`procedures.definition_defined` /
 * `.definition_updated` — 13 §111 governance trail).
 */
final class DefineProcedureCommand extends Command
{
    protected $signature = 'clinical:define-procedure
        {action : define|update}
        {--tenant= : tenant uuid}
        {--id= : definition uuid (required for update)}
        {--code= : procedure code (required for define)}
        {--name= : procedure name (required for define)}
        {--category= : category, e.g. DRESSING_WOUND_CARE}
        {--description= : description}
        {--protocol-reference= : approved direct-service protocol reference}
        {--clear-protocol-approval : remove direct-service protocol approval (update only)}
        {--capability= : required facility capability code}
        {--requires-site : the definition requires a body site}
        {--requires-laterality : the definition requires laterality}
        {--requires-readiness-check : READY is a required step for this procedure}
        {--active= : 1|0 — update only}';

    protected $description = 'Define or update a procedure catalog entry (ADR-146 D-C).';

    public function handle(ProcedureDefinitionRepository $definitions, AuditLogger $audit): int
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

    private function define(string $tenantId, ProcedureDefinitionRepository $definitions, AuditLogger $audit): int
    {
        $code = (string) $this->option('code');
        $name = (string) $this->option('name');

        if ($code === '' || $name === '') {
            $this->error('--code and --name are required for define.');

            return self::FAILURE;
        }

        $id = (string) Str::uuid7();

        $definition = ProcedureDefinition::define(
            id: $id,
            tenantId: $tenantId,
            code: $code,
            name: $name,
            category: $this->nullableOption('category'),
            description: $this->nullableOption('description'),
            protocolReference: $this->nullableOption('protocol-reference'),
            requiredCapabilityCode: $this->nullableOption('capability'),
            requiresSite: (bool) $this->option('requires-site'),
            requiresLaterality: (bool) $this->option('requires-laterality'),
            requiresReadinessCheck: (bool) $this->option('requires-readiness-check'),
        );

        $definitions->save($definition);

        $audit->record(
            action: 'procedures.definition_defined',
            targetType: 'procedure_definition',
            targetId: $id,
            metadata: [
                'code' => $code,
                'protocol_reference' => $definition->protocol_reference,
            ],
        );

        $this->info("Defined procedure [{$code}] ({$id}) v{$definition->version}");

        return self::SUCCESS;
    }

    private function update(ProcedureDefinitionRepository $definitions, AuditLogger $audit): int
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
            protocolReference: $this->nullableOption('protocol-reference'),
            requiredCapabilityCode: $this->nullableOption('capability'),
            requiresSite: $this->option('requires-site') ? true : null,
            requiresLaterality: $this->option('requires-laterality') ? true : null,
            requiresReadinessCheck: $this->option('requires-readiness-check') ? true : null,
            active: $active !== null ? $active !== '0' : null,
            clearProtocolApproval: (bool) $this->option('clear-protocol-approval'),
        );

        $definitions->save($definition);

        $audit->record(
            action: 'procedures.definition_updated',
            targetType: 'procedure_definition',
            targetId: $id,
            metadata: [
                'version' => $definition->version,
                'protocol_reference' => $definition->protocol_reference,
                'protocol_approval_cleared' => (bool) $this->option('clear-protocol-approval'),
            ],
        );

        $this->info("Updated procedure definition [{$id}] -> v{$definition->version}");

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
