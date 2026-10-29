<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\FinancialControlSettingsFactory;
use App\Domains\Billing\Domain\Money;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * A tenant's financial-control thresholds (20-billing-and-payments-domain.md
 * §74 / §157 / §165 / §167; ADR-191 G-3a).
 *
 * ONE row per tenant. Tenant-scoped, integer-versioned reference data —
 * CONSOLE-AUTHORED ONLY (`billing:configure-financial-control`), NO HTTP
 * command, NO permission key (the `EvaluationSettings` / ADR-175 D-B
 * precedent). A tenant with NO row ⇒ always dual-control (every write-off /
 * adjustment needs a distinct approver).
 *
 * @domain      Billing
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none — tenant-wide reference data
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $writeoff_dual_control_above
 * @property string|null $adjustment_dual_control_above
 * @property int $version
 */
final class FinancialControlSettings extends AggregateRoot
{
    /** @use HasFactory<FinancialControlSettingsFactory> */
    use HasFactory;

    public const KIND_WRITE_OFF = 'WRITE_OFF';

    public const KIND_ADJUSTMENT = 'ADJUSTMENT';

    protected $table = 'bill_financial_control_settings';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'writeoff_dual_control_above', 'adjustment_dual_control_above',
        'version',
    ];

    protected static function newFactory(): FinancialControlSettingsFactory
    {
        return FinancialControlSettingsFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'writeoff_dual_control_above' => 'decimal:2',
            'adjustment_dual_control_above' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    public static function initialise(string $id, string $tenantId): self
    {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'writeoff_dual_control_above' => null,
            'adjustment_dual_control_above' => null,
            'version' => 1,
        ]);
    }

    /**
     * The console write path ONLY. `null` leaves a value unchanged; `''` clears
     * it (⇒ always dual-control for that kind). Bumps `version`.
     */
    public function revise(?string $writeOffAbove, ?string $adjustmentAbove): void
    {
        if ($writeOffAbove !== null) {
            $this->writeoff_dual_control_above = $this->normalise($writeOffAbove, 'writeoff-dual-control-above');
        }

        if ($adjustmentAbove !== null) {
            $this->adjustment_dual_control_above = $this->normalise($adjustmentAbove, 'adjustment-dual-control-above');
        }

        $this->version = (int) $this->version + 1;
    }

    /** @return numeric-string|null the threshold for a kind, or null = always dual-control */
    public function thresholdFor(string $kind): ?string
    {
        $raw = $kind === self::KIND_WRITE_OFF
            ? $this->writeoff_dual_control_above
            : $this->adjustment_dual_control_above;

        return $raw !== null ? Money::of((string) $raw) : null;
    }

    private function normalise(string $value, string $option): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! is_numeric($value) || bccomp(Money::of($value), '0', 2) < 0) {
            throw new InvalidArgumentException("--{$option} must be a non-negative money amount, or \"\" to clear.");
        }

        return Money::of($value);
    }
}
