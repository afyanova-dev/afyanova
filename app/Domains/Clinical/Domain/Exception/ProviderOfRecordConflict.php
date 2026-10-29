<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A provider-of-record assignment could not be applied (12-clinical-domain.md
 * §4.1 "at most one active provider-of-record per encounter … an explicit,
 * audited event"; ADR-142 D-G / D-J / D-T). Maps to HTTP 409 (IC §15).
 *
 * The authoritative guard is the database partial unique index
 * `clin_one_provider_of_record_per_encounter` (ADR-142 D-T; IC §9.1); the
 * handler pre-checks for a friendly error and translates a concurrent-race
 * unique violation into {@see concurrentChange()}.
 *
 *   - `alreadyAssigned`        — `AddCareTeamMember(isProviderOfRecord: true)`
 *                                while the encounter already has an active
 *                                provider-of-record (use `ChangeProviderOfRecord`
 *                                for a hand-off).
 *   - `notAnActiveMember`      — `ChangeProviderOfRecord` named a staff user
 *                                who does not hold an ACTIVE membership on the
 *                                encounter (ADR-142 D-G — the incoming
 *                                provider-of-record must already be a member).
 *   - `alreadyProviderOfRecord`— `ChangeProviderOfRecord` named the staff user
 *                                who is already the active provider-of-record
 *                                (not a real transition — the `VerifyAllergy`
 *                                "not idempotent, 409" discipline, ADR-141 D-K).
 *   - `concurrentChange`       — the DB partial unique index rejected the write
 *                                because a concurrent request established a
 *                                different provider-of-record first (the
 *                                `ActiveEncounterExists` / ADR-121 race
 *                                precedent).
 */
final class ProviderOfRecordConflict extends DomainException
{
    public static function alreadyAssigned(string $encounterId): self
    {
        return new self(sprintf(
            'Encounter [%s] already has an active provider-of-record — use ChangeProviderOfRecord for a hand-off.',
            $encounterId,
        ));
    }

    public static function notAnActiveMember(string $encounterId, string $staffUserId): self
    {
        return new self(sprintf(
            'Staff user [%s] does not hold an active care-team membership on encounter [%s]; add the member before assigning provider-of-record.',
            $staffUserId,
            $encounterId,
        ));
    }

    public static function alreadyProviderOfRecord(string $encounterId, string $staffUserId): self
    {
        return new self(sprintf(
            'Staff user [%s] is already the active provider-of-record for encounter [%s].',
            $staffUserId,
            $encounterId,
        ));
    }

    public static function concurrentChange(string $encounterId): self
    {
        return new self(sprintf(
            'The provider-of-record for encounter [%s] was changed concurrently; retry against the current state.',
            $encounterId,
        ));
    }

    public function errorCode(): string
    {
        return 'provider_of_record_conflict';
    }
}
