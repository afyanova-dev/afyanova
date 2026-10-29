<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\Exception\PharmacyOrderAlreadyExists;
use App\Domains\Pharmacy\Domain\Exception\PrescriptionNotReadyForOrder;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\PrescriptionRepository;
use App\Domains\Pharmacy\Infrastructure\Sequence\PharmacyOrderNumberSequence;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `CreatePharmacyOrder` (ADR-182 D-D).
 *
 * Flow: load the Pharm.1 prescription (Pharmacy's own repo) → ADR-133
 * per-facility refinement at the prescription's ORIGINATING facility
 * (`pharmacy.orders.manage`) → the prescription must be `ACTIVE`
 * (`PrescriptionNotReadyForOrder`) → no non-terminal order already exists
 * (`hasOpenOrderForPrescription`; the partial unique index is the race backstop
 * → `PharmacyOrderAlreadyExists`) → allocate `RXO-%08d` → `PharmacyOrder::open()`
 * → save.
 *
 * `PharmacyOrderRepository` is the FIRST repository parameter so the bus
 * resolves `PharmacyOrderPolicy` (class-level `create`); `PrescriptionRepository`
 * is a later parameter (same module — no cross-domain call).
 */
final class CreatePharmacyOrderHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly PrescriptionRepository $prescriptions,
        private readonly PharmacyOrderNumberSequence $numbers,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreatePharmacyOrder $command): void
    {
        $prescription = $this->prescriptions->getById($command->prescriptionId);

        $this->assertActorScopedToFacility((string) $prescription->facility_id, $command->pharmacyOrderId);

        if ((string) $prescription->status !== Prescription::STATUS_ACTIVE) {
            throw PrescriptionNotReadyForOrder::status(
                (string) $prescription->id,
                (string) $prescription->status,
            );
        }

        if ($this->orders->hasOpenOrderForPrescription($command->prescriptionId)) {
            throw PharmacyOrderAlreadyExists::forPrescription($command->prescriptionId);
        }

        $now = CarbonImmutable::now();

        $order = PharmacyOrder::open(
            id: $command->pharmacyOrderId,
            tenantId: $this->context->tenantId(),
            facilityId: (string) $prescription->facility_id,
            prescriptionId: (string) $prescription->id,
            patientId: (string) $prescription->patient_id,
            orderNumber: $this->numbers->next($this->context->tenantId()),
            dispensingLocationId: $command->dispensingLocationId,
            priority: $command->priority,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->orders->save($order);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'rx_pharmacy_orders_one_open_per_prescription')) {
                throw PharmacyOrderAlreadyExists::forPrescription($command->prescriptionId);
            }
            throw $e;
        }
    }

    private function assertActorScopedToFacility(string $facilityId, string $pharmacyOrderId): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('CreatePharmacyOrder dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('pharmacy.orders.manage', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(PharmacyOrder::class, $pharmacyOrderId);
        }
    }
}
