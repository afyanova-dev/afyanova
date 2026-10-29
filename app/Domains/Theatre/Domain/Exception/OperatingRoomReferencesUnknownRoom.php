<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateOperatingRoom` named an `org_room_id` that does not exist at the
 * given facility, per `OrganizationApi::roomExists` (17-theatre-domain.md
 * §17; ADR-150 D-C/D-H). Maps to HTTP 409.
 */
final class OperatingRoomReferencesUnknownRoom extends DomainException
{
    public static function forRoom(string $facilityId, string $orgRoomId): self
    {
        return new self(sprintf(
            'Organization room [%s] does not exist at facility [%s].',
            $orgRoomId,
            $facilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'operating_room_references_unknown_room';
    }
}
