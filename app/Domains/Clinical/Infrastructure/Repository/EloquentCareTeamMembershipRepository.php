<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\CareTeamMembershipRepository;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see CareTeamMembershipRepository} (IC §9;
 * ADR-142).
 *
 * `save()` is inherited unchanged for `AddCareTeamMember` /
 * `EndCareTeamMembership` (a single versioned write + drain the released events
 * to the outbox). `changeProviderOfRecord()` persists BOTH instances of an
 * atomic provider-of-record hand-off in the bus transaction — the outgoing row
 * first (vacating the `clin_one_provider_of_record_per_encounter` partial unique
 * index), then the incoming row (which carries the single
 * `ProviderOfRecordChanged` event). This is the
 * `EloquentDiagnosisRepository::saveCorrection` two-instance precedent
 * (ADR-142 D-C).
 *
 * `getByIdForUpdate` narrows the return type and is provided to satisfy the
 * port; C.6 uses optimistic concurrency + the DB partial unique indexes, NOT
 * pessimistic locking (ADR-142 D-U), so the handlers call `getById`.
 */
final class EloquentCareTeamMembershipRepository extends EloquentRepository implements CareTeamMembershipRepository
{
    protected function model(): string
    {
        return CareTeamMembership::class;
    }

    public function getById(string $id): CareTeamMembership
    {
        return CareTeamMembership::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(CareTeamMembership::class, $id);
    }

    public function getByIdForUpdate(string $id): CareTeamMembership
    {
        return CareTeamMembership::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(CareTeamMembership::class, $id);
    }

    public function activeForEncounter(string $encounterId): array
    {
        return array_values(
            CareTeamMembership::query()
                ->where('encounter_id', $encounterId)
                ->whereNull('active_to')
                ->orderBy('active_from')
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        $this->outbox->writeAll($aggregate->releaseEvents());
    }

    public function changeProviderOfRecord(?CareTeamMembership $outgoing, CareTeamMembership $incoming): void
    {
        if ($outgoing !== null) {
            $outgoing->save();                              // versioned UPDATE — clears the old flag, vacates the partial unique index
        }

        $incoming->save();                                 // versioned UPDATE — sets the new flag (now unique)

        if ($outgoing !== null) {
            $this->outbox->writeAll($outgoing->releaseEvents()); // none — the outgoing records no event
        }

        $this->outbox->writeAll($incoming->releaseEvents());     // ProviderOfRecordChanged
    }
}
