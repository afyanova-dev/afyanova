<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Exception\SpecimenNotReadyForResult;
use App\Domains\Laboratory\Domain\LabOrderReader;
use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\LabSpecimenRepository;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Domains\Laboratory\Domain\Service\LabOrderLifecycle;
use App\Domains\Laboratory\Domain\Service\LabSpecimenLifecycle;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `RecordResult` (ADR-159 D-F).
 *
 * Flow: load the specimen (ACCEPTED | PROCESSING) -> ADR-133 per-facility
 * authorization refinement (`laboratory.results.enter` at the specimen's
 * facility -> NotFound) -> load the order-item context and check it belongs to
 * the specimen's order -> snapshot the catalog code/name + compute
 * `abnormal_flag` from the value vs the supplied range -> create the result
 * ENTERED -> advance the specimen (ACCEPTED -> PROCESSING) and the order
 * (PROCESSING -> RESULTED) on the first result. One transaction (three
 * aggregates — the CC.2 two-aggregate precedent generalised).
 */
final class RecordResultHandler
{
    public function __construct(
        private readonly LabResultRepository $results,
        private readonly LabSpecimenRepository $specimens,
        private readonly LabOrderRepository $orders,
        private readonly LabOrderReader $orderReader,
        private readonly LabSpecimenLifecycle $specimenLifecycle,
        private readonly LabOrderLifecycle $orderLifecycle,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordResult $command): LabResult
    {
        $specimen = $this->specimens->getById($command->specimenId);

        $this->assertActorScopedToFacility((string) $specimen->facility_id, $command->resultId);

        if (! in_array((string) $specimen->status, [LabSpecimen::STATUS_ACCEPTED, LabSpecimen::STATUS_PROCESSING], true)) {
            throw SpecimenNotReadyForResult::specimenStatus($command->specimenId, (string) $specimen->status);
        }

        $item = $this->orderReader->itemResultContext($command->orderItemId);

        if ($item->orderId !== (string) $specimen->order_id) {
            throw SpecimenNotReadyForResult::itemMismatch($command->orderItemId, $command->specimenId);
        }

        $parameterCode = self::blankToNull($command->parameterCode) ?? $item->testCode;
        $parameterName = self::blankToNull($command->parameterName) ?? $item->testName;

        $result = LabResult::record([
            'resultId' => $command->resultId,
            'tenantId' => $this->context->tenantId(),
            'facilityId' => (string) $specimen->facility_id,
            'orderId' => (string) $specimen->order_id,
            'orderItemId' => $command->orderItemId,
            'specimenId' => $command->specimenId,
            'patientId' => (string) $specimen->patient_id,
            'testDefinitionId' => $item->testDefinitionId,
            'testCode' => $item->testCode,
            'parameterCode' => strtoupper(trim($parameterCode)),
            'parameterName' => $parameterName,
            'valueType' => $command->valueType,
            'valueNumeric' => $command->valueNumeric,
            'valueCode' => self::normaliseCode($command->valueCode),
            'valueText' => self::blankToNull($command->valueText),
            'unit' => self::blankToNull($command->unit),
            'referenceLow' => $command->referenceLow,
            'referenceHigh' => $command->referenceHigh,
            'referenceText' => self::blankToNull($command->referenceText),
            'referenceRangeSource' => self::blankToNull($command->referenceRangeSource),
            'abnormalFlag' => self::computeFlag($command),
            'method' => self::blankToNull($command->method),
            'enteredByUserId' => $command->actorId,
            'occurredAt' => $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : null,
        ]);

        $this->results->save($result);

        if ((string) $specimen->status === LabSpecimen::STATUS_ACCEPTED) {
            $this->specimenLifecycle->assertCanStartProcessing((string) $specimen->status);
            $specimen->startProcessing();
            $this->specimens->save($specimen);
        }

        $order = $this->orders->getById((string) $specimen->order_id);
        if ((string) $order->status === LabOrder::STATUS_PROCESSING) {
            $this->orderLifecycle->assertCanMarkResulted((string) $order->status);
            $order->markResulted();
            $this->orders->save($order);
        }

        return $result;
    }

    private static function computeFlag(RecordResult $command): ?string
    {
        if ($command->valueType !== LabResult::VALUE_TYPE_NUMERIC || $command->valueNumeric === null) {
            return null;
        }

        if ($command->referenceHigh !== null && $command->valueNumeric > $command->referenceHigh) {
            return LabResult::FLAG_HIGH;
        }

        if ($command->referenceLow !== null && $command->valueNumeric < $command->referenceLow) {
            return LabResult::FLAG_LOW;
        }

        if ($command->referenceLow !== null || $command->referenceHigh !== null) {
            return LabResult::FLAG_NORMAL;
        }

        return null;
    }

    private function assertActorScopedToFacility(string $facilityId, string $resultId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordResult dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('laboratory.results.enter', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(LabResult::class, $resultId);
        }
    }

    private static function normaliseCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtoupper(trim($value));

        return $trimmed === '' ? null : $trimmed;
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
