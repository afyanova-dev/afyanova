<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Link the receiving encounter to an ACCEPTED referral
 * (26-care-coordination-domain.md §7 Invariant 7.5/7.6; ADR-151 D-G).
 *
 * Dispatched once the receiving facility's check-in has actually created
 * the encounter (NOT on `ReferralAccepted` — the domain doc's own literal
 * wording is corrected by ADR-151 D-G, since the receiving encounter often
 * doesn't exist yet at acceptance time). Sets `target_encounter_id` on the
 * referral AND — when the referral has a `source_encounter_id` — calls
 * `EncounterApi::recordEncounterRelationship` to write the reciprocal
 * REFERRED_FROM relationship on the NEW encounter (`$relationshipId` is
 * client-minted for that row). Facility-scoped to the receiving facility.
 */
final readonly class LinkReferralEncounter implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $referralId,
        public string $encounterId,
        public string $relationshipId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('LinkReferralEncounter::$referralId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('LinkReferralEncounter::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($relationshipId)) {
            throw new InvalidArgumentException('LinkReferralEncounter::$relationshipId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('LinkReferralEncounter::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'linkEncounter';
    }

    public function subjectId(): string
    {
        return $this->referralId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.referral_encounter_linked',
            actorId: $this->actorId,
            targetType: 'referral',
            targetId: $this->referralId,
            metadata: [
                'encounter_id' => $this->encounterId,
            ],
        );
    }
}
