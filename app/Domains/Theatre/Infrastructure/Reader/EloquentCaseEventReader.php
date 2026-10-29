<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Reader;

use App\Domains\Theatre\Contracts\Dto\CaseEventSummary;
use App\Domains\Theatre\Domain\CaseEventReader;
use App\Domains\Theatre\Domain\Model\CaseEvent;

final class EloquentCaseEventReader implements CaseEventReader
{
    /**
     * @return list<CaseEventSummary>
     */
    public function forCase(string $caseId): array
    {
        $rows = CaseEvent::query()
            ->where('case_id', $caseId)
            ->orderBy('event_at')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = new CaseEventSummary(
                caseEventId: (string) $row->id,
                facilityId: (string) $row->facility_id,
                caseId: (string) $row->case_id,
                eventType: (string) $row->event_type,
                eventAt: $row->event_at?->toISOString() ?? '',
                note: $row->note,
                recordedByUserId: (string) $row->recorded_by_user_id,
                createdAt: $row->created_at?->toISOString(),
            );
        }

        return $out;
    }
}
