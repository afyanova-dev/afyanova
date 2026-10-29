<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Exception\EncounterAlreadyHasActiveCase;
use App\Domains\Theatre\Domain\Exception\OperatingRoomAlreadyInUse;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `MarkCaseReady` (ADR-150 D-D/D-E/D-G). The two partial unique
 * indexes on `thr_surgical_cases` (D-E) are defense-in-depth here — by
 * construction only one case can hold a room past `AssignOperatingRoom`
 * (that command's own optimistic-lock guard on `OperatingRoom.version`), but
 * the constraint is still translated to a domain exception if ever hit (the
 * `AssignBedHandler` precedent).
 */
final class MarkCaseReadyHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(MarkCaseReady $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanMarkReady((string) $case->status, $case->operating_room_id, $case->safety_checklist);

        $case->markReady();

        try {
            $this->cases->save($case);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'thr_surgical_cases_one_active_per_room')) {
                throw OperatingRoomAlreadyInUse::forRoom((string) $case->operating_room_id);
            }

            if (str_contains($e->getMessage(), 'thr_surgical_cases_one_active_per_encounter')) {
                throw EncounterAlreadyHasActiveCase::forEncounter((string) $case->encounter_id);
            }

            throw $e;
        }
    }
}
