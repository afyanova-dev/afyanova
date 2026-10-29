<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Contracts\DTO\PatientMovementDto;
use App\Platform\Queueing\Domain\Model\PatientMovement;
use App\Platform\Queueing\Domain\Repository\PatientMovementRepository;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

final readonly class RecordPatientMovementHandler
{
    public function __construct(
        private PatientMovementRepository $movementRepository,
        private Container $container,
    ) {}

    private function tenantContext(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }

    public function __invoke(RecordPatientMovementCommand $command): PatientMovementDto
    {
        $context = $this->tenantContext();
        $tenantId = $context->tenantId();
        $facilityId = $context->facilityId() ?? (string) Str::uuid7();
        $now = CarbonImmutable::now();

        $movement = new PatientMovement([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'encounter_id' => $command->encounterId,
            'patient_id' => $command->patientId,
            'from_area' => $command->fromArea,
            'to_area' => $command->toArea,
            'status' => $command->status,
            'queue_entry_id' => $command->queueEntryId,
            'actor_id' => $command->actorId,
            'reason' => $command->reason,
            'occurred_at' => $now,
        ]);

        $this->movementRepository->save($movement);

        return new PatientMovementDto(
            id: (string) $movement->id,
            encounterId: $movement->encounter_id,
            patientId: $movement->patient_id,
            fromArea: $movement->from_area,
            toArea: $movement->to_area,
            status: $movement->status,
            queueEntryId: $movement->queue_entry_id,
            actorId: $movement->actor_id,
            reason: $movement->reason,
            occurredAt: $movement->occurred_at,
        );
    }
}
