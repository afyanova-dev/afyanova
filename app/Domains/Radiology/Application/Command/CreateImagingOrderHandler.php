<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Radiology\Contracts\Dto\ImagingServiceSummary;
use App\Domains\Radiology\Domain\Exception\EncounterNotEligibleForImagingOrder;
use App\Domains\Radiology\Domain\Exception\ImagingOrderContextMismatch;
use App\Domains\Radiology\Domain\Exception\ImagingServiceNotOrderable;
use App\Domains\Radiology\Domain\Exception\PatientNotEligibleForImagingOrder;
use App\Domains\Radiology\Domain\ImagingModalityReader;
use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\ImagingServiceReader;
use App\Domains\Radiology\Domain\Model\ImagingModality;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Model\ImagingOrderItem;
use App\Domains\Radiology\Domain\Model\ImagingService;
use App\Domains\Radiology\Infrastructure\Sequence\OrderNumberSequence;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates `CreateImagingOrder` (ADR-165 D-E/D-J).
 *
 * Flow: `PatientApi::getPatientSummary` patient-state gate (ACTIVE, incl.
 * provisional) -> `EncounterApi::getEncounterContext` -> ADR-133-style
 * per-encounter-facility authorization refinement -> patient/encounter
 * consistency -> encounter-state gate (ARRIVED|ACTIVE|COMPLETED — the
 * `CreateLabOrder` precedent) and `facility_id` = the encounter's facility ->
 * resolve every ordered service against the catalog (snapshot code / name /
 * version / modality code; reject inactive / DISABLED / referral-only; reject a
 * SUSPENDED parent modality; enforce the service's + modality's
 * `required_capability_code` via `FacilityCapabilities::has`) -> allocate
 * `order_number` (`OrderNumberSequence`, ADR-115 pattern) -> create DRAFT ->
 * versioned save + `ImagingOrderCreated` -> write `rad_imaging_order_items`
 * (one txn).
 */
final class CreateImagingOrderHandler
{
    public function __construct(
        private readonly ImagingOrderRepository $orders,
        private readonly ImagingServiceReader $services,
        private readonly ImagingModalityReader $modalities,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly FacilityCapabilities $capabilities,
        private readonly OrderNumberSequence $orderNumbers,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateImagingOrder $command): ImagingOrder
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->orderId);

        if ($encounter->patientId !== $command->patientId) {
            throw ImagingOrderContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForImagingOrder::status($encounter->encounterId, $encounter->status);
        }

        $facilityId = $encounter->facilityId;

        /** @var list<ImagingServiceSummary> $resolved */
        $resolved = [];
        foreach ($command->serviceIds as $serviceId) {
            $resolved[] = $this->resolveOrderableService($serviceId, $facilityId);
        }

        $tenantId = $this->context->tenantId();
        $orderNumber = $this->orderNumbers->next($tenantId);

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $order = ImagingOrder::create(
            id: $command->orderId,
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            orderNumber: $orderNumber,
            priority: strtoupper(trim($command->priority)),
            clinicalIndicationRef: $command->clinicalIndicationRef,
            clinicalIndicationText: $command->clinicalIndicationText !== null ? trim($command->clinicalIndicationText) : null,
            createdByUserId: $command->actorId,
            occurredAt: $occurredAt,
        );

        $this->orders->save($order);

        foreach ($resolved as $service) {
            ImagingOrderItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'order_id' => $command->orderId,
                'imaging_service_id' => $service->serviceId,
                'service_code' => $service->code,
                'service_name' => $service->name,
                'service_definition_version' => $service->version,
                'modality_code' => $service->modalityCode,
                'body_site' => $service->bodySite,
                'laterality' => $service->defaultLaterality,
                'status' => ImagingOrderItem::STATUS_ORDERED,
            ]);
        }

        return $order;
    }

    private function resolveOrderableService(string $serviceId, string $facilityId): ImagingServiceSummary
    {
        $service = $this->services->summary($serviceId); // NotFound if unknown

        if (! $service->active || $service->availabilityStatus === ImagingService::AVAILABILITY_DISABLED) {
            throw ImagingServiceNotOrderable::unavailable($serviceId);
        }

        if ($service->referralOnly) {
            throw ImagingServiceNotOrderable::referralOnly($serviceId);
        }

        $modality = $this->modalities->summary($service->modalityId);

        if (! $modality->active || $modality->operationalStatus === ImagingModality::STATUS_SUSPENDED) {
            throw ImagingServiceNotOrderable::modalityNotOperational($serviceId, $modality->code);
        }

        foreach ([$service->requiredCapabilityCode, $modality->requiredCapabilityCode] as $capabilityCode) {
            if ($capabilityCode !== null && ! $this->capabilities->has($facilityId, $capabilityCode)) {
                throw ImagingServiceNotOrderable::capabilityMissing($serviceId, $capabilityCode, $facilityId);
            }
        }

        return $service;
    }

    private function assertPatientEligible(PatientSummary $patient): void
    {
        if ($patient->status === 'MERGED') {
            throw PatientNotEligibleForImagingOrder::merged($patient->patientId);
        }

        if ($patient->status === 'DECEASED') {
            throw PatientNotEligibleForImagingOrder::deceased($patient->patientId);
        }

        if ($patient->status === 'INACTIVE') {
            throw PatientNotEligibleForImagingOrder::inactive($patient->patientId);
        }
    }

    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $orderId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreateImagingOrder dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'radiology.orders.create',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(ImagingOrder::class, $orderId);
        }
    }
}
