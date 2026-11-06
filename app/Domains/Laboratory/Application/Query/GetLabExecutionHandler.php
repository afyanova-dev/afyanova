<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabExecutionSummary;
use App\Domains\Laboratory\Domain\LabExecutionReader;
use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetLabExecutionHandler
{
    public function __construct(
        private readonly LabExecutionReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetLabExecution $query): LabExecutionSummary
    {
        $execution = LabExecution::query()->whereKey($query->executionId)->first()
            ?? throw NotFound::aggregate(LabExecution::class, $query->executionId);
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('GetLabExecution dispatched without an authenticated user.');
        }

        if (! $this->scope->hasFacilityPermissionInContext(
            $user,
            $this->context,
            (string) $execution->facility_id,
            'laboratory.access',
        )) {
            throw NotFound::aggregate(LabExecution::class, $query->executionId);
        }

        return $this->reader->summary($query->executionId);
    }
}
