<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Console;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Procurement\Application\ApprovalLimitResolver;
use App\Domains\Procurement\Domain\ApprovalPolicyRepository;
use App\Domains\Procurement\Domain\Model\ApprovalPolicy;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Ops entry point for single-actor approval limits (Shape B;
 * 19-procurement-domain.md §24, §45, §228).
 *
 * A limit belongs to a FACILITY, optionally narrowed to a DEPARTMENT, because a
 * dispensary, a health centre and a hospital buy at completely different
 * scales. `pcr_approval_policies` is operator policy, not deployment config —
 * this command is the interim authoring path until the Procurement settings
 * screen lands.
 *
 *   # the whole facility: one person may approve + issue up to TZS 500,000
 *   procurement:configure-approval-limit --tenant=… --facility=… --limit=500000
 *
 *   # the laboratory alone: reagents need a tighter limit
 *   procurement:configure-approval-limit --tenant=… --facility=… \
 *       --department=<org department id> --limit=200000
 *
 *   # back to the facility row / facility-type default
 *   procurement:configure-approval-limit --tenant=… --facility=… --department=<id> --clear
 *
 *   # what is set today, and what actually applies
 *   procurement:configure-approval-limit --tenant=… --facility=… --list
 *
 * `--limit=0` is different from `--clear`: `0` pins the scope to "never allow
 * one person to approve their own order", while `--clear` falls back to the
 * wider scope. Every write is audited.
 */
final class ConfigureApprovalLimitCommand extends Command
{
    protected $signature = 'procurement:configure-approval-limit
        {--tenant= : tenant uuid}
        {--facility= : facility uuid}
        {--department= : organization department id (a department override)}
        {--limit= : money amount in TZS; 0 = never allow single-actor approval}
        {--clear : remove the row for this scope}
        {--list : show the facility\'s rows and the effective limits}';

    protected $description = 'Set the per-facility / per-department single-actor approval limit (Shape B).';

    public function handle(
        ApprovalPolicyRepository $policies,
        ApprovalLimitResolver $limits,
        OrganizationApi $organization,
        AuditLogger $audit,
    ): int {
        $tenantId = trim((string) $this->option('tenant'));
        $facilityId = trim((string) $this->option('facility'));

        try {
            $departmentRef = $this->departmentOption();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        if ($facilityId === '') {
            $this->error('--facility is required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use (
            $tenantId, $facilityId, $departmentRef, $policies, $limits, $organization, $audit
        ): int {
            if (! $organization->facilityExists($facilityId)) {
                $this->error("Facility [{$facilityId}] does not exist in tenant [{$tenantId}].");

                return self::FAILURE;
            }

            if ($this->option('list')) {
                return $this->listFor($facilityId, $policies, $limits, $organization);
            }

            if ($this->option('clear')) {
                return $this->clear($facilityId, $departmentRef, $policies, $audit);
            }

            return $this->set($facilityId, $departmentRef, $policies, $audit);
        });
    }

    private function set(
        string $facilityId,
        ?string $departmentRef,
        ApprovalPolicyRepository $policies,
        AuditLogger $audit,
    ): int {
        $limit = trim((string) $this->option('limit'));

        if ($limit === '') {
            $this->error('--limit is required unless --clear or --list is used.');

            return self::FAILURE;
        }

        try {
            $row = $policies->findFor($facilityId, $departmentRef);
            $created = $row === null;

            if ($row === null) {
                $row = ApprovalPolicy::define($facilityId, $departmentRef, $limit);
            } else {
                $row->reviseLimit($limit);
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $policies->save($row);

        $audit->record(
            action: 'procurement.approval_limit_configured',
            targetType: 'approval_policy',
            targetId: (string) $row->id,
            metadata: [
                'facility_id' => $facilityId,
                'department_ref' => $departmentRef,
                'self_approval_limit' => (string) $row->self_approval_limit,
                'created' => $created,
            ],
        );

        $this->info(sprintf(
            '%s %s limit → %s %s (%s)',
            $created ? 'Set' : 'Updated',
            $departmentRef === null ? 'facility' : 'department',
            (string) $row->self_approval_limit,
            (string) $row->currency,
            $departmentRef ?? $facilityId,
        ));

        return self::SUCCESS;
    }

    private function clear(
        string $facilityId,
        ?string $departmentRef,
        ApprovalPolicyRepository $policies,
        AuditLogger $audit,
    ): int {
        $row = $policies->findFor($facilityId, $departmentRef);

        if ($row === null) {
            $this->info('No row for that scope — nothing to clear.');

            return self::SUCCESS;
        }

        $policies->delete($row);

        $audit->record(
            action: 'procurement.approval_limit_cleared',
            targetType: 'approval_policy',
            targetId: (string) $row->id,
            metadata: [
                'facility_id' => $facilityId,
                'department_ref' => $departmentRef,
            ],
        );

        $this->info(sprintf(
            'Cleared %s limit at [%s].',
            $departmentRef === null ? 'facility' : 'department',
            $departmentRef ?? $facilityId,
        ));

        return self::SUCCESS;
    }

    private function listFor(
        string $facilityId,
        ApprovalPolicyRepository $policies,
        ApprovalLimitResolver $limits,
        OrganizationApi $organization,
    ): int {
        $facilityType = $organization->facilityType($facilityId) ?? 'unknown';

        $this->line(sprintf('Facility [%s] — type %s', $facilityId, $facilityType));

        $rows = $policies->listForFacility($facilityId);

        if ($rows === []) {
            $this->line('No policy rows — the facility-type default applies.');
        } else {
            foreach ($rows as $row) {
                $this->line(sprintf(
                    '  %-12s %s  %s %s',
                    $row->isDepartmentScoped() ? 'department' : 'facility',
                    $row->isDepartmentScoped() ? (string) $row->department_ref : '(all)',
                    (string) $row->self_approval_limit,
                    (string) $row->currency,
                ));
            }
        }

        $this->line(sprintf(
            'Effective facility-wide limit: %s TZS',
            $limits->resolve($facilityId, null),
        ));

        return self::SUCCESS;
    }

    /**
     * `--department` must be the exact `requesting_department_ref` a
     * requisition carries — opaque to Procurement (§224), so it is validated
     * for shape only, never looked up across the boundary.
     */
    private function departmentOption(): ?string
    {
        $value = $this->option('department');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException('--department must be a uuid.');
        }

        return $value;
    }
}
