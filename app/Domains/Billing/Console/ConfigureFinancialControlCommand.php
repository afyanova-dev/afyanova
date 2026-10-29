<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Domain\FinancialControlSettingsRepository;
use App\Domains\Billing\Domain\Model\FinancialControlSettings;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * PHASE 10 (increment Bill.7a) ops entry point for the per-tenant
 * financial-control thresholds (20-billing-and-payments-domain.md §74 / §157 /
 * §165; ADR-191 G-3a — the `procurement:configure-evaluation` precedent).
 *
 * `bill_financial_control_settings` is CONSOLE-ONLY — NO HTTP bus command, NO
 * permission key. Initialise-or-revise the tenant's one row; every change is
 * audited (`billing.financial_control_configured`). An omitted option leaves
 * the value unchanged; `--writeoff-dual-control-above=""` clears it (⇒ always
 * dual-control for write-offs).
 */
final class ConfigureFinancialControlCommand extends Command
{
    protected $signature = 'billing:configure-financial-control
        {--tenant= : tenant uuid}
        {--writeoff-dual-control-above= : a money amount, or "" to clear (⇒ always dual-control)}
        {--adjustment-dual-control-above= : a money amount, or "" to clear (⇒ always dual-control)}';

    protected $description = 'Configure a tenant\'s write-off / adjustment dual-control thresholds (ADR-191 G-3a).';

    public function handle(FinancialControlSettingsRepository $settings, AuditLogger $audit): int
    {
        $tenantId = (string) $this->option('tenant');

        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $settings, $audit): int {
            $row = $settings->findForTenant($tenantId);
            $created = $row === null;
            $row ??= FinancialControlSettings::initialise((string) Str::uuid7(), $tenantId);

            try {
                $row->revise(
                    writeOffAbove: $this->rawOption('writeoff-dual-control-above'),
                    adjustmentAbove: $this->rawOption('adjustment-dual-control-above'),
                );
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $settings->save($row);

            $audit->record(
                action: 'billing.financial_control_configured',
                targetType: 'financial_control_settings',
                targetId: (string) $row->id,
                metadata: [
                    'version' => (int) $row->version,
                    'created' => $created,
                    'writeoff_dual_control_above' => $row->writeoff_dual_control_above,
                    'adjustment_dual_control_above' => $row->adjustment_dual_control_above,
                ],
            );

            $this->info(sprintf(
                '%s tenant [%s] financial-control thresholds → v%d (write-off %s / adjustment %s)',
                $created ? 'Initialised' : 'Updated',
                $tenantId,
                (int) $row->version,
                $row->writeoff_dual_control_above ?? 'always dual-control',
                $row->adjustment_dual_control_above ?? 'always dual-control',
            ));

            return self::SUCCESS;
        });
    }

    /** The raw string option, or null when the flag was not passed at all. */
    private function rawOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
