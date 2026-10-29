<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Console;

use App\Domains\Procurement\Domain\EvaluationSettingsRepository;
use App\Domains\Procurement\Domain\Exception\EvaluationSettingsInvalid;
use App\Domains\Procurement\Domain\Model\EvaluationSettings;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * PHASE 9 (increment Proc.2c) ops entry point for per-tenant evaluation-criteria
 * authoring (19-procurement-domain.md §23, §36, §205; ADR-175 D-F — the
 * `clinical:define-procedure` precedent).
 *
 * `pcr_evaluation_settings` is CONSOLE-ONLY — there is NO HTTP bus command and
 * NO permission key. Initialise-or-revise the tenant's one row; every change is
 * audited (`procurement.evaluation_configured`). Omitted options leave the
 * current value unchanged; `--pass-threshold=""` / `--evaluation-required-above=""`
 * clear the field.
 */
final class ConfigureEvaluationCommand extends Command
{
    protected $signature = 'procurement:configure-evaluation
        {--tenant= : tenant uuid}
        {--commercial-weight= : 0..100}
        {--technical-weight= : 0..100}
        {--quality-weight= : 0..100}
        {--delivery-weight= : 0..100}
        {--commercial-required= : 1|0}
        {--technical-required= : 1|0}
        {--quality-required= : 1|0}
        {--delivery-required= : 1|0}
        {--pass-threshold= : 0..100, or "" to clear}
        {--evaluation-required-above= : a money amount, or "" to clear}';

    protected $description = 'Configure a tenant\'s quotation-evaluation criteria (ADR-175 D-F).';

    public function handle(EvaluationSettingsRepository $settings, AuditLogger $audit): int
    {
        $tenantId = (string) $this->option('tenant');

        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $settings, $audit): int {
            $row = $settings->findForTenant($tenantId);
            $created = $row === null;

            if ($row === null) {
                $row = EvaluationSettings::initialise((string) Str::uuid7(), $tenantId);
            }

            try {
                $row->revise(
                    weights: array_filter([
                        'commercial' => $this->intOption('commercial-weight'),
                        'technical' => $this->intOption('technical-weight'),
                        'quality' => $this->intOption('quality-weight'),
                        'delivery' => $this->intOption('delivery-weight'),
                    ], static fn (?int $v): bool => $v !== null),
                    mandatory: array_filter([
                        'commercial' => $this->boolOption('commercial-required'),
                        'technical' => $this->boolOption('technical-required'),
                        'quality' => $this->boolOption('quality-required'),
                        'delivery' => $this->boolOption('delivery-required'),
                    ], static fn (?bool $v): bool => $v !== null),
                    passThreshold: $this->rawOption('pass-threshold'),
                    evaluationRequiredAbove: $this->rawOption('evaluation-required-above'),
                );
            } catch (InvalidArgumentException|EvaluationSettingsInvalid $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $settings->save($row);

            $audit->record(
                action: 'procurement.evaluation_configured',
                targetType: 'evaluation_settings',
                targetId: (string) $row->id,
                metadata: ['version' => (int) $row->version, 'created' => $created],
            );

            $this->info(sprintf(
                '%s tenant [%s] evaluation criteria → v%d (weights %d/%d/%d/%d)',
                $created ? 'Initialised' : 'Updated',
                $tenantId,
                (int) $row->version,
                (int) $row->commercial_weight,
                (int) $row->technical_weight,
                (int) $row->quality_weight,
                (int) $row->delivery_weight,
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

    private function intOption(string $name): ?int
    {
        $value = $this->rawOption($name);

        if ($value === null || trim($value) === '') {
            return null;
        }

        if (! ctype_digit(trim($value))) {
            throw new InvalidArgumentException("--{$name} must be a non-negative integer.");
        }

        return (int) $value;
    }

    private function boolOption(string $name): ?bool
    {
        $value = $this->rawOption($name);

        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value) !== '0';
    }
}
