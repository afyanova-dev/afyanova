<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Plan;
use App\Domains\Insurance\Domain\ValueObject\PlanStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The tenant's payer plans, optionally filtered by payer / status
 * (21-insurance-and-payer-domain.md §12, §160; ADR-198 D-I). `view` gates on
 * the coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Plan::class)]
final readonly class SearchPayerPlans
{
    public ?string $payerId;

    public ?string $status;

    public function __construct(?string $payerId = null, ?string $status = null)
    {
        if ($payerId !== null && ! Str::isUuid($payerId)) {
            throw new InvalidArgumentException('SearchPayerPlans::$payerId must be a UUID or null (IC §3).');
        }
        if ($status !== null && ! PlanStatus::isValid($status)) {
            throw new InvalidArgumentException('SearchPayerPlans::$status must be ACTIVE | INACTIVE or null.');
        }

        $this->payerId = $payerId;
        $this->status = $status !== null ? strtoupper(trim($status)) : null;
    }
}
