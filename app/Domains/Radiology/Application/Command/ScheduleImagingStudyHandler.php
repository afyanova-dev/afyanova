<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Contracts\Dto\ImagingOrderItemSummary;
use App\Domains\Radiology\Contracts\Dto\ImagingOrderSummary;
use App\Domains\Radiology\Domain\Exception\ImagingOrderNotReadyForStudy;
use App\Domains\Radiology\Domain\Exception\ImagingStudyAlreadyActive;
use App\Domains\Radiology\Domain\ImagingOrderReader;
use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\ImagingStudyRepository;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Model\ImagingOrderItem;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;
use App\Domains\Radiology\Infrastructure\Sequence\AccessionNumberSequence;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `ScheduleImagingStudy` (ADR-166 D-F). `ImagingStudyRepository` is
 * injected FIRST so the bus resolves `ImagingStudyPolicy` (IC §5.1).
 *
 * Flow: load the order (aggregate — for the status gate + the pre-reserved
 * rollup) -> ADR-133 per-order-facility authorization refinement -> order ∈
 * {ACCEPTED, SCHEDULED, IN_PROGRESS} -> resolve the order item off the order
 * summary (must belong to the order, must be ORDERED) -> no non-terminal study
 * already exists for that item -> allocate the accession -> create the study in
 * SCHEDULED, snapshotting patient / encounter / service / modality / site ->
 * if the order is still ACCEPTED, advance it to SCHEDULED (one transaction, two
 * aggregates — the Lab.2 `CollectSpecimen` precedent).
 */
final class ScheduleImagingStudyHandler
{
    public function __construct(
        private readonly ImagingStudyRepository $studies,
        private readonly ImagingOrderRepository $orders,
        private readonly ImagingOrderReader $orderReader,
        private readonly AccessionNumberSequence $accessions,
        private readonly ImagingOrderLifecycle $orderLifecycle,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(ScheduleImagingStudy $command): ImagingStudy
    {
        $order = $this->orders->getById($command->orderId);

        $this->assertActorScopedToOrderFacility((string) $order->facility_id, $command->studyId);

        if (! in_array((string) $order->status, [
            ImagingOrder::STATUS_ACCEPTED,
            ImagingOrder::STATUS_SCHEDULED,
            ImagingOrder::STATUS_IN_PROGRESS,
        ], true)) {
            throw ImagingOrderNotReadyForStudy::status($command->orderId, (string) $order->status);
        }

        $item = $this->resolveOrderableItem($this->orderReader->summary($command->orderId), $command);

        if (($active = $this->studies->activeForOrderItem($command->orderItemId)) !== null) {
            throw ImagingStudyAlreadyActive::forOrderItem($command->orderItemId, (string) $active->id);
        }

        $tenantId = $this->context->tenantId();
        $accessionNumber = $this->accessions->next($tenantId);

        $study = ImagingStudy::schedule([
            'id' => $command->studyId,
            'tenantId' => $tenantId,
            'facilityId' => (string) $order->facility_id,
            'orderId' => (string) $order->id,
            'orderItemId' => $command->orderItemId,
            'patientId' => (string) $order->patient_id,
            'encounterId' => (string) $order->encounter_id,
            'accessionNumber' => $accessionNumber,
            'modalityCode' => $item->modalityCode,
            'serviceCode' => $item->serviceCode,
            'bodySite' => $item->bodySite,
            'laterality' => $item->laterality,
            'scheduledFor' => $command->scheduledFor !== null ? CarbonImmutable::parse($command->scheduledFor) : null,
            'appointmentRef' => self::normalise($command->appointmentRef),
            'equipmentRef' => self::normalise($command->equipmentRef),
            'repeatOfStudyId' => $command->repeatOfStudyId,
            'createdByUserId' => $command->actorId,
        ]);

        $this->studies->save($study);

        if ((string) $order->status === ImagingOrder::STATUS_ACCEPTED) {
            $this->orderLifecycle->assertCanMarkScheduled((string) $order->status);
            $order->markScheduled();
            $this->orders->save($order);
        }

        return $study;
    }

    private function resolveOrderableItem(ImagingOrderSummary $summary, ScheduleImagingStudy $command): ImagingOrderItemSummary
    {
        foreach ($summary->items as $item) {
            if ($item->itemId === $command->orderItemId) {
                if ($item->status !== ImagingOrderItem::STATUS_ORDERED) {
                    throw ImagingOrderNotReadyForStudy::unknownItem($command->orderId, $command->orderItemId);
                }

                return $item;
            }
        }

        throw ImagingOrderNotReadyForStudy::unknownItem($command->orderId, $command->orderItemId);
    }

    private function assertActorScopedToOrderFacility(string $facilityId, string $studyId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('ScheduleImagingStudy dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('radiology.studies.perform', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(ImagingStudy::class, $studyId);
        }
    }

    private static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
