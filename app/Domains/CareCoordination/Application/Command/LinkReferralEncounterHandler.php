<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\ReferralRepository;
use App\Domains\CareCoordination\Domain\Service\ReferralLifecycle;
use App\Domains\Encounter\Contracts\EncounterApi;

/**
 * Orchestrates `LinkReferralEncounter` (ADR-151 D-G). Calls
 * `EncounterApi::recordEncounterRelationship` — NOT a direct CommandBus
 * dispatch of Encounter's internal `RecordEncounterRelationship` command,
 * which would cross the Contracts-only module-import boundary (IC §2.1;
 * the same fix Theatre's `CompleteCaseHandler` needed for `BedWardApi::
 * assignBed`).
 */
final class LinkReferralEncounterHandler
{
    public function __construct(
        private readonly ReferralRepository $referrals,
        private readonly ReferralLifecycle $lifecycle,
        private readonly EncounterApi $encounters,
    ) {}

    public function __invoke(LinkReferralEncounter $command): void
    {
        $referral = $this->referrals->getById($command->referralId);

        $this->lifecycle->assertCanLinkEncounter((string) $referral->status);

        $sourceEncounterId = $referral->source_encounter_id;

        $referral->linkEncounter($command->encounterId);

        $this->referrals->save($referral);

        if ($sourceEncounterId !== null) {
            $this->encounters->recordEncounterRelationship(
                relationshipId: $command->relationshipId,
                encounterId: $command->encounterId,
                relatedEncounterId: $sourceEncounterId,
                relationshipType: 'REFERRED_FROM',
                actorId: $command->actorId,
            );
        }
    }
}
