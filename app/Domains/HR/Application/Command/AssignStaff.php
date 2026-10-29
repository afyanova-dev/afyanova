<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\AssignmentScope;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class AssignStaff implements AuthorizesAgainst
{
    public string $assignmentId;

    public AssignmentScope $scope;

    public CarbonImmutable $effectiveFrom;

    public ?CarbonImmutable $effectiveTo;

    public function __construct(
        public string $staffId,
        public string $facilityId,
        string|AssignmentScope $scope,
        string|CarbonImmutable $effectiveFrom,
        public ?string $departmentId = null,
        public ?string $unitId = null,
        string|CarbonImmutable|null $effectiveTo = null,
        public bool $isPrimary = false,
        ?string $assignmentId = null,
    ) {
        $this->assignmentId = $assignmentId ?? (string) Str::uuid();
        $this->scope = is_string($scope) ? AssignmentScope::from($scope) : $scope;
        $this->effectiveFrom = is_string($effectiveFrom) ? CarbonImmutable::parse($effectiveFrom) : $effectiveFrom;
        $this->effectiveTo = is_string($effectiveTo) ? CarbonImmutable::parse($effectiveTo) : $effectiveTo;
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
