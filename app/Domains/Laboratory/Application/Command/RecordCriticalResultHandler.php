<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Exception\CriticalResultAlreadyRecorded;
use App\Domains\Laboratory\Domain\LabCriticalResultRepository;
use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Domains\Laboratory\Domain\Service\LabCriticalResultLifecycle;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `RecordCriticalResult` (ADR-161 D-F).
 *
 * Flow: load the result (must be VERIFIED / RELEASED / AMENDED) -> ADR-133
 * per-facility authorization refinement -> create the `LabCriticalResult`
 * INITIATED (snapshotting the result's order / patient / codes) -> flag the
 * `LabResult` CRITICAL -> emit `CriticalLabResultIdentified`. One transaction
 * (two aggregates). `unique(tenant_id, result_id)` collision ->
 * `CriticalResultAlreadyRecorded`.
 */
final class RecordCriticalResultHandler
{
    public function __construct(
        private readonly LabCriticalResultRepository $criticals,
        private readonly LabResultRepository $results,
        private readonly LabCriticalResultLifecycle $lifecycle,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordCriticalResult $command): LabCriticalResult
    {
        $result = $this->results->getByIdForUpdate($command->resultId);

        $this->assertActorScopedToFacility((string) $result->facility_id, $command->criticalResultId);

        $this->lifecycle->assertResultCanBeFlagged((string) $result->status);

        $critical = LabCriticalResult::identify(
            id: $command->criticalResultId,
            tenantId: $this->context->tenantId(),
            facilityId: (string) $result->facility_id,
            resultId: (string) $result->id,
            orderId: (string) $result->order_id,
            patientId: (string) $result->patient_id,
            testCode: (string) $result->test_code,
            parameterCode: (string) $result->parameter_code,
            severity: strtoupper(trim($command->severity)),
            clinicalContext: self::blankToNull($command->clinicalContext),
            identifiedByUserId: $command->actorId,
        );

        try {
            $this->criticals->save($critical);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'lab_critical_results_tenant_id_result_id_unique')) {
                throw CriticalResultAlreadyRecorded::forResult($command->resultId);
            }

            throw $e;
        }

        $result->flagCritical();
        $this->results->save($result);

        return $critical;
    }

    private function assertActorScopedToFacility(string $facilityId, string $criticalResultId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordCriticalResult dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('laboratory.critical.manage', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(LabCriticalResult::class, $criticalResultId);
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
