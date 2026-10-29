<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class DefineLeaveType implements AuthorizesAgainst
{
    public string $leaveTypeId;

    public function __construct(
        public string $code,
        public string $name,
        public bool $isPaid = true,
        public float $accrualRatePerYear = 0.00,
        public ?float $maxBalance = null,
        public bool $allowNegative = false,
        public ?float $carryOverLimit = null,
        public bool $isActive = true,
        ?string $leaveTypeId = null,
    ) {
        $this->leaveTypeId = $leaveTypeId ?? (string) Str::uuid();
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
