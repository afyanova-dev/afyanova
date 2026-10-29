<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Contracts\Event\RosterPublished;
use App\Domains\HR\Domain\RosterEntryRepository;
use App\Shared\Events\OutboxWriter;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Handles PublishRoster command (25-hr-and-workforce-domain.md §7; ADR-206).
 */
final class PublishRosterHandler
{
    public function __construct(
        private readonly RosterEntryRepository $rosterEntries,
        private readonly OutboxWriter $outbox,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(PublishRoster $command): void
    {
        $drafts = $this->rosterEntries->getDraftsForFacilityAndPeriod(
            $command->facilityId,
            $command->startDate,
            $command->endDate
        );

        $publishedIds = [];
        foreach ($drafts as $entry) {
            $entry->publish();
            $this->rosterEntries->save($entry);
            $publishedIds[] = $entry->id;
        }

        $this->outbox->write(new RosterPublished(
            eventId: (string) Str::uuid(),
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            startDate: $command->startDate->toDateString(),
            endDate: $command->endDate->toDateString(),
            publishedEntryIds: $publishedIds,
            publishedCount: count($publishedIds),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }
}
