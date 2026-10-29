<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Pharmacy\Domain\Exception\TranscriptionMedicationNotInCatalog;
use App\Domains\Pharmacy\Domain\Exception\TranscriptionSourceMismatch;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\PrescriptionRepository;
use App\Domains\Pharmacy\Domain\Service\PrescriptionLifecycle;

/**
 * Orchestrates `AcceptTranscription` (Pharm.4).
 *
 * Flow: `getByIdForUpdate` → `assertAcceptable` (RECEIVED only) → refuse an
 * electronic row (a decision-linked prescription accepts through
 * `AcceptPrescription` only — the two paths never cross) →
 * `->acceptTranscription()` writes the typed snapshot + records
 * `TranscriptionAccepted` → save.
 *
 * `PrescriptionRepository` first → the bus resolves
 * `PrescriptionPolicy::accept` (per-subject, at the prescription's facility).
 */
final class AcceptTranscriptionHandler
{
    public function __construct(
        private readonly PrescriptionRepository $prescriptions,
        private readonly PrescriptionLifecycle $lifecycle,
        private readonly InventoryApi $inventory,
    ) {}

    public function __invoke(AcceptTranscription $command): void
    {
        $prescription = $this->prescriptions->getByIdForUpdate($command->prescriptionId);

        $this->lifecycle->assertAcceptable((string) $prescription->status);

        if (! in_array((string) $prescription->source, [
            Prescription::SOURCE_PAPER_TRANSCRIPTION,
            Prescription::SOURCE_EXTERNAL_PAPER,
        ], true)) {
            throw new TranscriptionSourceMismatch($command->prescriptionId);
        }

        $itemId = (string) $command->medication['inventory_item_id'];
        $item = $this->inventory->getItem($itemId);
        if ($item === null || ! $item->active || $item->itemType !== 'MEDICATION') {
            throw new TranscriptionMedicationNotInCatalog();
        }

        $prescription->acceptTranscription([
            ...$command->medication,
            'medication_name' => $item->name,
            'medication_code' => $item->code,
        ], $command->actorId);

        $this->prescriptions->save($prescription);
    }
}
