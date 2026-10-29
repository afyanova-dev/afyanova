<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\AssignmentScope;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class AmendAssignment implements AuthorizesAgainst
{
    public ?AssignmentScope $scope;

    public ?CarbonImmutable $effectiveFrom;

    public ?CarbonImmutable $effectiveTo;

    public function __construct(
        public string $assignmentId,
        public ?string $departmentId = null,
        public ?string $unitId = null,
        string|AssignmentScope|null $scope = null,
        string|CarbonImmutable|null $effectiveFrom = null,
        string|CarbonImmutable|null $effectiveTo = null,
        public ?bool $isPrimary = null,
    ) {
        $this->scope = is_string($scope) ? AssignmentScope::from($scope) : $scope;
        $this->effectiveFrom = is_string($effectiveFrom) ? CarbonImmutable::parse($effectiveFrom) : $effectiveFrom;
        $this->effectiveTo = is_string($effectiveTo) ? CarbonImmutable::parse($effectiveTo) : $effectiveTo;
    }

    public function policyAbility(): string
    {
        return 'amend';
    }

    public function subjectId(): string
    {
        return $this->assignmentId;
    }
}
