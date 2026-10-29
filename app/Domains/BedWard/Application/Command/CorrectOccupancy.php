<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Privileged correction of occupancy records (24-bed-and-ward-management-domain.md §9;
 * ADR-148 D-H).
 *
 * Bounded-mutation + audit-log-only (UpdatePatient precedent). NOT Auditable via
 * the bus because the handler produces the field-level before/after diff.
 */
final readonly class CorrectOccupancy implements AuthorizesAgainst
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public string $occupancyId,
        public string $actorId,
        public array $changes,
        public string $reason,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($occupancyId)) {
            throw new InvalidArgumentException('CorrectOccupancy::$occupancyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CorrectOccupancy::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('CorrectOccupancy::$reason must be a non-blank string.');
        }

        $invalid = array_diff(array_keys($changes), BedOccupancy::CORRECTABLE_FIELDS);
        if ($invalid !== []) {
            throw new InvalidArgumentException(sprintf(
                'CorrectOccupancy: unpermitted fields [%s]. Permitted: %s.',
                implode(', ', $invalid),
                implode(', ', BedOccupancy::CORRECTABLE_FIELDS),
            ));
        }
    }

    public function policyAbility(): string
    {
        return 'correct';
    }

    public function subjectId(): string
    {
        return $this->occupancyId;
    }
}
