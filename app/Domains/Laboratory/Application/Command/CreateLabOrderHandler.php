<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;
use App\Domains\Laboratory\Domain\Exception\EncounterNotEligibleForLabOrder;
use App\Domains\Laboratory\Domain\Exception\LabOrderContextMismatch;
use App\Domains\Laboratory\Domain\Exception\LabPanelNotOrderable;
use App\Domains\Laboratory\Domain\Exception\LabTestNotOrderable;
use App\Domains\Laboratory\Domain\Exception\PatientNotEligibleForLabOrder;
use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\LabPanelReader;
use App\Domains\Laboratory\Domain\LabTestDefinitionReader;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Domains\Laboratory\Infrastructure\Sequence\OrderNumberSequence;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
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
 * Orchestrates `CreateLabOrder` (ADR-157 D-D/D-I).
 *
 * Flow: `PatientApi::getPatientSummary` patient-state gate (ACTIVE, incl.
 * provisional) -> `EncounterApi::getEncounterContext` -> ADR-133-style
 * per-encounter-facility authorization refinement -> patient/encounter
 * consistency -> encounter-state gate (ARRIVED|ACTIVE|COMPLETED — the
 * `CreateClinicalNote` precedent) and `facility_id` = the encounter's facility
 * -> resolve every ordered test against the catalog (snapshot code / name /
 * version; reject inactive / DISABLED; enforce `required_capability_code` via
 * `FacilityCapabilities::has` — the CP.1 `RequestProcedure` precedent) ->
 * allocate `order_number` (`OrderNumberSequence`, ADR-115 pattern) -> create
 * DRAFT -> versioned save + `LabOrderCreated` -> write `lab_order_items`
 * (one txn).
 */
final class CreateLabOrderHandler
{
    public function __construct(
        private readonly LabOrderRepository $orders,
        private readonly LabTestDefinitionReader $definitions,
        private readonly LabPanelReader $panels,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly FacilityCapabilities $capabilities,
        private readonly OrderNumberSequence $orderNumbers,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateLabOrder $command): LabOrder
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->orderId);

        if ($encounter->patientId !== $command->patientId) {
            throw LabOrderContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForLabOrder::status($encounter->encounterId, $encounter->status);
        }

        $facilityId = $encounter->facilityId;

        // Build the (definitionId => panelId|null) map: explicit tests are untagged;
        // panel members are tagged with their panel (an explicitly-ordered test wins,
        // staying untagged). ADR-162 D-D.
        $wanted = [];
        foreach ($command->testDefinitionIds as $definitionId) {
            $wanted[$definitionId] = null;
        }
        foreach ($command->panelIds as $panelId) {
            foreach ($this->resolvePanelMembers($panelId) as $memberDefinitionId) {
                if (! array_key_exists($memberDefinitionId, $wanted)) {
                    $wanted[$memberDefinitionId] = $panelId;
                }
            }
        }

        /** @var array<string, array{definition: LabTestDefinitionSummary, panelId: string|null}> $resolved */
        $resolved = [];
        foreach ($wanted as $definitionId => $panelId) {
            $resolved[$definitionId] = [
                'definition' => $this->resolveOrderableTest($definitionId, $facilityId),
                'panelId' => $panelId,
            ];
        }

        $tenantId = $this->context->tenantId();
        $orderNumber = $this->orderNumbers->next($tenantId);

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $order = LabOrder::create(
            id: $command->orderId,
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            orderNumber: $orderNumber,
            priority: strtoupper(trim($command->priority)),
            clinicalIndicationRef: $command->clinicalIndicationRef,
            clinicalIndicationText: $command->clinicalIndicationText,
            createdByUserId: $command->actorId,
            occurredAt: $occurredAt,
        );

        $this->orders->save($order);

        foreach ($resolved as $entry) {
            $definition = $entry['definition'];
            LabOrderItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'order_id' => $command->orderId,
                'test_definition_id' => $definition->definitionId,
                'test_code' => $definition->code,
                'test_name' => $definition->name,
                'test_definition_version' => $definition->version,
                'status' => LabOrderItem::STATUS_ORDERED,
                'ordered_via_panel_id' => $entry['panelId'],
            ]);
        }

        return $order;
    }

    /**
     * @return list<string> the test-definition ids in a panel
     */
    private function resolvePanelMembers(string $panelId): array
    {
        $panel = $this->panels->summary($panelId); // NotFound if unknown

        if (! $panel->active) {
            throw LabPanelNotOrderable::inactive($panelId);
        }

        if ($panel->members === []) {
            throw LabPanelNotOrderable::empty($panelId);
        }

        return array_map(
            static fn (array $m): string => $m['testDefinitionId'],
            $panel->members,
        );
    }

    private function resolveOrderableTest(string $definitionId, string $facilityId): LabTestDefinitionSummary
    {
        $definition = $this->definitions->summary($definitionId);

        if (! $definition->active || $definition->availabilityStatus === LabTestDefinition::AVAILABILITY_DISABLED) {
            throw LabTestNotOrderable::unavailable($definitionId);
        }

        if ($definition->requiredCapabilityCode !== null
            && ! $this->capabilities->has($facilityId, $definition->requiredCapabilityCode)) {
            throw LabTestNotOrderable::capabilityMissing($definitionId, $definition->requiredCapabilityCode, $facilityId);
        }

        return $definition;
    }

    private function assertPatientEligible(PatientSummary $patient): void
    {
        if ($patient->status === 'MERGED') {
            throw PatientNotEligibleForLabOrder::merged($patient->patientId);
        }

        if ($patient->status === 'DECEASED') {
            throw PatientNotEligibleForLabOrder::deceased($patient->patientId);
        }

        if ($patient->status === 'INACTIVE') {
            throw PatientNotEligibleForLabOrder::inactive($patient->patientId);
        }
    }

    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $orderId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreateLabOrder dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'laboratory.orders.create',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(LabOrder::class, $orderId);
        }
    }
}
