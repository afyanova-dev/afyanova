<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Model\OperativeNoteAmendment;
use App\Domains\Theatre\Domain\OperativeNoteRepository;
use App\Domains\Theatre\Domain\Service\OperativeNoteLifecycle;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `AmendOperativeNote` (ADR-152 D-C/D-D). Writes the plain
 * `thr_operative_note_amendments` child row directly and flips the note to
 * AMENDED (the first time) via the aggregate.
 *
 * The note is loaded `getByIdForUpdate` (row lock — the MergePatients
 * ADR-128 M-13 precedent): a second amendment on an ALREADY-AMENDED note
 * dirties no aggregate attribute, so the optimistic `version` check is a
 * no-op and cannot serialize concurrent amendments — the FOR UPDATE lock
 * does, so `nextAmendmentSequence` never hands two rows the same number.
 */
final class AmendOperativeNoteHandler
{
    public function __construct(
        private readonly OperativeNoteRepository $notes,
        private readonly OperativeNoteLifecycle $lifecycle,
    ) {}

    public function __invoke(AmendOperativeNote $command): void
    {
        $note = $this->notes->getByIdForUpdate($command->noteId);

        $this->lifecycle->assertCanAmend((string) $note->status);

        $sequence = $this->notes->nextAmendmentSequence((string) $note->id);

        OperativeNoteAmendment::create([
            'id' => $command->amendmentId,
            'tenant_id' => $note->tenant_id,
            'facility_id' => $note->facility_id,
            'operative_note_id' => $note->id,
            'sequence' => $sequence,
            'amendment_text' => $command->amendmentText,
            'reason' => trim($command->reason),
            'created_by_user_id' => $command->actorId,
            'created_at' => CarbonImmutable::now(),
        ]);

        $note->recordAmendment($command->amendmentId, $sequence);

        $this->notes->save($note);
    }
}
