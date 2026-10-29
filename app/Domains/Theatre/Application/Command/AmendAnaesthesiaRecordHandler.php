<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\AnaesthesiaRecordRepository;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecordAmendment;
use App\Domains\Theatre\Domain\Service\AnaesthesiaRecordLifecycle;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `AmendAnaesthesiaRecord` (ADR-155 D-E). Writes the plain
 * `thr_anaesthesia_record_amendments` child row and flips the record to
 * AMENDED (the first time) via the aggregate.
 *
 * Loaded `getByIdForUpdate` — a second amendment on an ALREADY-AMENDED record
 * dirties no aggregate attribute, so the optimistic `version` check cannot
 * serialize concurrent amendments; the FOR UPDATE lock does (the T.2
 * AmendOperativeNote lesson).
 */
final class AmendAnaesthesiaRecordHandler
{
    public function __construct(
        private readonly AnaesthesiaRecordRepository $records,
        private readonly AnaesthesiaRecordLifecycle $lifecycle,
    ) {}

    public function __invoke(AmendAnaesthesiaRecord $command): void
    {
        $record = $this->records->getByIdForUpdate($command->recordId);

        $this->lifecycle->assertCanAmend((string) $record->status);

        $sequence = $this->records->nextAmendmentSequence((string) $record->id);

        AnaesthesiaRecordAmendment::create([
            'id' => $command->amendmentId,
            'tenant_id' => $record->tenant_id,
            'facility_id' => $record->facility_id,
            'anaesthesia_record_id' => $record->id,
            'sequence' => $sequence,
            'amendment_text' => $command->amendmentText,
            'reason' => trim($command->reason),
            'created_by_user_id' => $command->actorId,
            'created_at' => CarbonImmutable::now(),
        ]);

        $record->recordAmendment($command->amendmentId, $sequence);

        $this->records->save($record);
    }
}
