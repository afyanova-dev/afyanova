<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Exception\LabOrderNotReadyForSpecimen;
use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\LabSpecimenRepository;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Domains\Laboratory\Domain\Service\LabOrderLifecycle;
use App\Domains\Laboratory\Infrastructure\Sequence\AccessionNumberSequence;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `CollectSpecimen` (ADR-158 D-F).
 *
 * Flow: load the order -> ADR-133 per-order-facility authorization refinement
 * (`laboratory.specimens.collect` at the order's facility -> NotFound) -> order
 * must be ACCEPTED / SPECIMEN_PENDING / PROCESSING -> allocate the accession
 * number -> create the specimen in COLLECTED (snapshotting the order's
 * facility_id + patient_id — wrong-patient safety) -> if the order is still
 * ACCEPTED, advance it to SPECIMEN_PENDING. One transaction (two aggregates —
 * the CC.2 `LinkEncounterToEpisode` precedent).
 */
final class CollectSpecimenHandler
{
    public function __construct(
        private readonly LabSpecimenRepository $specimens,
        private readonly LabOrderRepository $orders,
        private readonly AccessionNumberSequence $accessions,
        private readonly LabOrderLifecycle $orderLifecycle,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CollectSpecimen $command): LabSpecimen
    {
        $order = $this->orders->getById($command->orderId);

        $this->assertActorScopedToOrderFacility((string) $order->facility_id, $command->specimenId);

        if (! in_array((string) $order->status, [
            LabOrder::STATUS_ACCEPTED,
            LabOrder::STATUS_SPECIMEN_PENDING,
            LabOrder::STATUS_PROCESSING,
        ], true)) {
            throw LabOrderNotReadyForSpecimen::status($command->orderId, (string) $order->status);
        }

        $accessionNumber = $this->accessions->next($this->context->tenantId());

        $specimen = LabSpecimen::collect(
            id: $command->specimenId,
            tenantId: $this->context->tenantId(),
            facilityId: (string) $order->facility_id,
            orderId: $command->orderId,
            patientId: (string) $order->patient_id,
            accessionNumber: $accessionNumber,
            specimenType: strtoupper(trim($command->specimenType)),
            containerType: self::normalise($command->containerType),
            collectionLocation: self::normalise($command->collectionLocation),
            collectedByUserId: $command->collectedByUserId ?? $command->actorId,
            createdByUserId: $command->actorId,
            collectedAt: $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : null,
            recollectionOfSpecimenId: $command->recollectionOfSpecimenId,
        );

        $this->specimens->save($specimen);

        if ((string) $order->status === LabOrder::STATUS_ACCEPTED) {
            $this->orderLifecycle->assertCanMarkSpecimenPending((string) $order->status);
            $order->markSpecimenPending();
            $this->orders->save($order);
        }

        return $specimen;
    }

    private function assertActorScopedToOrderFacility(string $facilityId, string $specimenId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CollectSpecimen dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('laboratory.specimens.collect', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(LabSpecimen::class, $specimenId);
        }
    }

    private static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtoupper(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }
}
