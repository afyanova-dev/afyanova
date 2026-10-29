<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabQcRunRepository;
use App\Domains\Laboratory\Domain\LabResultReader;
use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\LabTestDefinitionReader;
use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Domains\Laboratory\Domain\Service\LabQcRunLifecycle;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `RecordQcRun` (ADR-163 D-F).
 *
 * Flow: ADR-133 per-facility authorization refinement -> snapshot the test code
 * from the catalog (if a test is named) -> classify PASS / FAIL -> save the
 * run (emits `QcRunRecorded`, plus `QcRunFailed` on FAIL) -> on FAIL, apply a
 * `QC_FAILED` release hold to every VERIFIED-not-released result at the
 * facility for that test (§85). One transaction.
 */
final class RecordQcRunHandler
{
    /** The reserved `LabResult::release_hold_reason` value for a failed QC run (ADR-159 D-E). */
    public const QC_HOLD_REASON = 'QC_FAILED';

    public function __construct(
        private readonly LabQcRunRepository $runs,
        private readonly LabResultRepository $results,
        private readonly LabResultReader $resultReader,
        private readonly LabTestDefinitionReader $definitions,
        private readonly LabQcRunLifecycle $lifecycle,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordQcRun $command): LabQcRun
    {
        $facilityId = $this->context->requireFacilityId();

        $this->assertActorScopedToFacility($facilityId, $command->qcRunId);

        $testCode = null;
        if ($command->testDefinitionId !== null) {
            $testCode = $this->definitions->summary($command->testDefinitionId)->code; // NotFound if unknown
        }

        $status = $this->lifecycle->classify($command->observedValue, $command->expectedLow, $command->expectedHigh);

        $run = LabQcRun::record([
            'qcRunId' => $command->qcRunId,
            'tenantId' => $this->context->tenantId(),
            'facilityId' => $facilityId,
            'analyzerRef' => trim($command->analyzerRef),
            'testDefinitionId' => $command->testDefinitionId,
            'testCode' => $testCode,
            'controlMaterial' => trim($command->controlMaterial),
            'controlLevel' => self::blankToNull($command->controlLevel),
            'expectedLow' => $command->expectedLow,
            'expectedHigh' => $command->expectedHigh,
            'observedValue' => $command->observedValue,
            'unit' => self::blankToNull($command->unit),
            'status' => $status,
            'performedByUserId' => $command->actorId,
            'occurredAt' => $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : null,
        ]);

        $this->runs->save($run);

        if ($status === LabQcRun::STATUS_FAIL) {
            foreach ($this->resultReader->verifiedUnreleasedIdsForTest($facilityId, $command->testDefinitionId) as $resultId) {
                $result = $this->results->getByIdForUpdate($resultId);
                $result->applyReleaseHold(self::QC_HOLD_REASON);
                $this->results->save($result);
            }
        }

        return $run;
    }

    private function assertActorScopedToFacility(string $facilityId, string $qcRunId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordQcRun dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('laboratory.quality.manage', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(LabQcRun::class, $qcRunId);
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
