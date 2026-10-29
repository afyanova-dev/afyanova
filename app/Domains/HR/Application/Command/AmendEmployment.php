<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\ContractType;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class AmendEmployment implements AuthorizesAgainst
{
    public ContractType $contractType;

    public ?CarbonImmutable $endDate;

    public ?CarbonImmutable $probationEndDate;

    public function __construct(
        public string $employmentId,
        public string $positionId,
        string|ContractType $contractType,
        public string $fte,
        string|CarbonImmutable|null $endDate = null,
        string|CarbonImmutable|null $probationEndDate = null,
        public int $noticePeriodDays = 30,
        public ?string $termsSummary = null,
    ) {
        $this->contractType = is_string($contractType)
            ? ContractType::from($contractType)
            : $contractType;
        $this->endDate = is_string($endDate)
            ? CarbonImmutable::parse($endDate)
            : $endDate;
        $this->probationEndDate = is_string($probationEndDate)
            ? CarbonImmutable::parse($probationEndDate)
            : $probationEndDate;
    }

    public function policyAbility(): string
    {
        return 'amend';
    }

    public function subjectId(): string
    {
        return $this->employmentId;
    }
}
