<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Activate a transcribed paper prescription by typing its medication snapshot
 * from the paper (Pharm.4) — the paper-path twin of `AcceptPrescription`.
 * Per-subject `accept`; RECEIVED only.
 */
final readonly class AcceptTranscription implements Auditable, AuthorizesAgainst
{
    /**
     * @param  array{inventory_item_id: string, dose?: ?string, frequency?: ?string, duration?: ?string, prescribed_quantity?: ?string, dosing_instruction?: ?string, route?: ?string, urgency?: ?string}  $medication
     */
    public function __construct(
        public string $prescriptionId,
        public string $actorId,
        public array $medication,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($prescriptionId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcceptTranscription ids must be UUIDs (IC §3).');
        }

        $itemId = $medication['inventory_item_id'];
        if (! Str::isUuid($itemId)) {
            throw new InvalidArgumentException('AcceptTranscription requires a catalog inventory_item_id UUID.');
        }
    }

    public function policyAbility(): string
    {
        return 'accept';
    }

    public function subjectId(): string
    {
        return $this->prescriptionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.transcription_accepted',
            actorId: $this->actorId,
            targetType: 'prescription',
            targetId: $this->prescriptionId,
            metadata: [
                'inventory_item_id' => $this->medication['inventory_item_id'],
            ],
        );
    }
}
