<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\CareTeamMembershipSummary;
use App\Domains\Clinical\Domain\CareTeamMembershipReader;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Shared\Domain\NotFound;

final class EloquentCareTeamMembershipReader implements CareTeamMembershipReader
{
    public function summary(string $membershipId): CareTeamMembershipSummary
    {
        $membership = CareTeamMembership::query()->whereKey($membershipId)->first()
            ?? throw NotFound::aggregate(CareTeamMembership::class, $membershipId);

        return $this->toSummary($membership);
    }

    public function forEncounter(string $encounterId, array $inScopeFacilityIds, bool $includeEnded = false): array
    {
        if ($inScopeFacilityIds === []) {
            return [];
        }

        $query = CareTeamMembership::query()
            ->where('encounter_id', $encounterId)
            ->whereIn('facility_id', $inScopeFacilityIds);

        if (! $includeEnded) {
            $query->whereNull('active_to');
        }

        $summaries = $query
            ->orderByDesc('is_provider_of_record')
            ->orderBy('team_role')
            ->orderBy('active_from')
            ->orderBy('id')
            ->get()
            ->map(fn (CareTeamMembership $membership): CareTeamMembershipSummary => $this->toSummary($membership))
            ->all();

        return array_values($summaries);
    }

    private function toSummary(CareTeamMembership $membership): CareTeamMembershipSummary
    {
        return new CareTeamMembershipSummary(
            membershipId: (string) $membership->id,
            encounterId: (string) $membership->encounter_id,
            patientId: (string) $membership->patient_id,
            facilityId: (string) $membership->facility_id,
            staffUserId: (string) $membership->staff_user_id,
            teamRole: (string) $membership->team_role,
            isProviderOfRecord: (bool) $membership->is_provider_of_record,
            activeFrom: (string) $membership->active_from->toISOString(),
            activeTo: $membership->active_to?->toISOString(),
            addedByUserId: (string) $membership->added_by_user_id,
            removedByUserId: $membership->removed_by_user_id,
            endReason: $membership->end_reason,
            addedAt: (string) $membership->created_at->toISOString(),
            version: (int) $membership->version,
        );
    }
}
