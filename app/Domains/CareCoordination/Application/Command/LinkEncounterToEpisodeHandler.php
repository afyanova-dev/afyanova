<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\CareEpisodeEncounterRepository;
use App\Domains\CareCoordination\Domain\CareEpisodeRepository;
use App\Domains\CareCoordination\Domain\Exception\CareEpisodeContextMismatch;
use App\Domains\CareCoordination\Domain\Exception\EncounterAlreadyLinkedToEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;
use App\Domains\CareCoordination\Domain\Service\CareEpisodeLifecycle;
use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `LinkEncounterToEpisode` (ADR-153 D-E/D-I).
 *
 * `CareEpisodeEncounterRepository` is injected FIRST so the bus resolves
 * `CareEpisodeEncounterPolicy` for the class-level create check (IC §5.1).
 * Flow: load the episode (must be OPEN) -> `EncounterApi::getEncounterContext`
 * (existence + the ADR-133 per-encounter-facility auth refinement) ->
 * `encounter.patientId == episode.patientId` -> copy facility/patient from
 * the encounter -> create LINKED + `EncounterLinkedToEpisode` -> versioned
 * save; a collision on the one-active-link partial index surfaces as
 * `EncounterAlreadyLinkedToEpisode` (409).
 */
final class LinkEncounterToEpisodeHandler
{
    public function __construct(
        private readonly CareEpisodeEncounterRepository $links,
        private readonly CareEpisodeRepository $episodes,
        private readonly EncounterApi $encounters,
        private readonly CareEpisodeLifecycle $lifecycle,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(LinkEncounterToEpisode $command): CareEpisodeEncounter
    {
        $episode = $this->episodes->getById($command->episodeId);

        $this->lifecycle->assertCanLinkEncounter((string) $episode->status);

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->linkId);

        if ($encounter->patientId !== (string) $episode->patient_id) {
            throw CareEpisodeContextMismatch::patientEncounter(
                (string) $episode->patient_id,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $link = CareEpisodeEncounter::link(
            id: $command->linkId,
            tenantId: (string) $episode->tenant_id,
            facilityId: $encounter->facilityId,
            careEpisodeId: (string) $episode->id,
            encounterId: $command->encounterId,
            patientId: (string) $episode->patient_id,
            role: $command->role !== null ? trim($command->role) : null,
            addedReason: $command->reason !== null && trim($command->reason) !== '' ? trim($command->reason) : null,
            createdByUserId: $command->actorId,
        );

        try {
            $this->links->save($link);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'care_episode_encounters_one_active_link')) {
                throw EncounterAlreadyLinkedToEpisode::forPair((string) $episode->id, $command->encounterId);
            }

            throw $e;
        }

        return $link;
    }

    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $linkId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('LinkEncounterToEpisode dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'care_coordination.episode.manage',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(CareEpisode::class, $linkId);
        }
    }
}
