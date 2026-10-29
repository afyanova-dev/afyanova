<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Writer;

use App\Domains\Pharmacy\Application\Command\AcceptTranscription;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\TranscribePaperPrescription;
use App\Shared\Bus\CommandBus;
use Illuminate\Support\Facades\DB;

final class TranscriptionBatchWriter
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @param  list<array{
     *     prescription_id: string,
     *     pharmacy_order_id: string,
     *     inventory_item_id: string,
     *     dose?: string|null,
     *     frequency?: string|null,
     *     duration?: string|null,
     *     prescribed_quantity?: string|null,
     *     dosing_instruction?: string|null,
     *     route?: string|null,
     *     urgency?: string|null
     * }>  $lines
     */
    public function submit(
        string $facilityId,
        array $lines,
        string $patientId,
        string $actorId,
        string $prescriberName,
        ?string $transcriptionNote,
        ?string $externalIntakeId,
    ): void {
        DB::transaction(function () use (
            $facilityId,
            $lines,
            $patientId,
            $actorId,
            $prescriberName,
            $transcriptionNote,
            $externalIntakeId,
        ): void {
            foreach ($lines as $line) {
                $prescriptionId = $line['prescription_id'];
                $orderId = $line['pharmacy_order_id'];

                $this->commandBus->dispatch(new TranscribePaperPrescription(
                    prescriptionId: $prescriptionId,
                    actorId: $actorId,
                    patientId: $patientId,
                    facilityId: $facilityId,
                    prescriberName: $prescriberName,
                    transcriptionNote: $transcriptionNote,
                    externalIntakeId: $externalIntakeId,
                    idempotencyKey: $prescriptionId,
                ));

                $this->commandBus->dispatch(new AcceptTranscription(
                    prescriptionId: $prescriptionId,
                    actorId: $actorId,
                    medication: [
                        'inventory_item_id' => $line['inventory_item_id'],
                        'dose' => $line['dose'] ?? null,
                        'frequency' => $line['frequency'] ?? null,
                        'duration' => $line['duration'] ?? null,
                        'prescribed_quantity' => $line['prescribed_quantity'] ?? null,
                        'dosing_instruction' => $line['dosing_instruction'] ?? null,
                        'route' => $line['route'] ?? null,
                        'urgency' => $line['urgency'] ?? null,
                    ],
                    idempotencyKey: $prescriptionId,
                ));

                $this->commandBus->dispatch(new CreatePharmacyOrder(
                    pharmacyOrderId: $orderId,
                    actorId: $actorId,
                    prescriptionId: $prescriptionId,
                    idempotencyKey: $orderId,
                ));
            }
        });
    }
}
