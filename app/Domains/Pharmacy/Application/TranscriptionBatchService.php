<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application;

use App\Domains\Pharmacy\Infrastructure\Writer\TranscriptionBatchWriter;

final class TranscriptionBatchService
{
    public function __construct(private readonly TranscriptionBatchWriter $writer) {}

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
        $this->writer->submit(
            $facilityId,
            $lines,
            $patientId,
            $actorId,
            $prescriberName,
            $transcriptionNote,
            $externalIntakeId,
        );
    }
}
