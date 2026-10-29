<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\LeaveTypeDefined;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Leave type policy definition aggregate root (25-hr-and-workforce-domain.md §6, §7.9; ADR-206).
 *
 * @domain      HR
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property bool $is_paid
 * @property float $accrual_rate_per_year
 * @property float|null $max_balance
 * @property bool $allow_negative
 * @property float|null $carry_over_limit
 * @property bool $is_active
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class LeaveType extends AggregateRoot
{
    protected $table = 'hr_leave_types';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'is_paid',
        'accrual_rate_per_year',
        'max_balance',
        'allow_negative',
        'carry_over_limit',
        'is_active',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_paid' => 'boolean',
        'accrual_rate_per_year' => 'float',
        'max_balance' => 'float',
        'allow_negative' => 'boolean',
        'carry_over_limit' => 'float',
        'is_active' => 'boolean',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function define(
        string $tenantId,
        string $code,
        string $name,
        bool $isPaid = true,
        float $accrualRatePerYear = 0.00,
        ?float $maxBalance = null,
        bool $allowNegative = false,
        ?float $carryOverLimit = null,
        bool $isActive = true,
        ?string $leaveTypeId = null,
    ): self {
        $id = $leaveTypeId ?? (string) Str::uuid();

        $type = new self([
            'tenant_id' => $tenantId,
            'code' => trim($code),
            'name' => trim($name),
            'is_paid' => $isPaid,
            'accrual_rate_per_year' => $accrualRatePerYear,
            'max_balance' => $maxBalance,
            'allow_negative' => $allowNegative,
            'carry_over_limit' => $carryOverLimit,
            'is_active' => $isActive,
            'version' => 0,
        ]);

        $type->id = $id;

        $type->recordThat(new LeaveTypeDefined(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            leaveTypeId: $id,
            code: $type->code,
            name: $type->name,
            isPaid: $isPaid,
            accrualRatePerYear: $accrualRatePerYear,
            maxBalance: $maxBalance,
            allowNegative: $allowNegative,
            carryOverLimit: $carryOverLimit,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $type;
    }
}
