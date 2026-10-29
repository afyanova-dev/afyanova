<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\ContractType;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class CreateEmployment implements AuthorizesAgainst
{
    public string $employmentId;

    public ContractType $contractType;

    public CarbonImmutable $startDate;

    public ?CarbonImmutable $endDate;

    public ?CarbonImmutable $probationEndDate;

    public function __construct(
        public string $staffId,
        public string $positionId,
        string|ContractType $contractType,
        string|CarbonImmutable $startDate,
        public string $fte = '1.00',
        ?string $employmentId = null,
        string|CarbonImmutable|null $endDate = null,
        string|CarbonImmutable|null $probationEndDate = null,
        public int $noticePeriodDays = 30,
        public ?string $termsSummary = null,
    ) {
        $this->employmentId = $employmentId ?? (string) Str::uuid();
        $this->contractType = is_string($contractType)
            ? ContractType::from($contractType)
            : $contractType;
        $this->startDate = is_string($startDate)
            ? CarbonImmutable::parse($startDate)
            : $startDate;
        $this->endDate = is_string($endDate)
            ? CarbonImmutable::parse($endDate)
            : $endDate;
        $this->probationEndDate = is_string($probationEndDate)
            ? CarbonImmutable::parse($probationEndDate)
            : $probationEndDate;
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
