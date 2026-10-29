<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Exception\OperativeNoteAlreadyExistsForCase;
use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Domains\Theatre\Domain\OperativeNoteRepository;
use App\Domains\Theatre\Domain\Service\OperativeNoteLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `CreateOperativeNote` (ADR-152 D-C/D-D). `OperativeNoteRepository`
 * is injected FIRST so the bus resolves `OperativeNotePolicy` for the
 * `AuthorizesAgainst` class-level check (IC §5.1). The SurgicalCase is
 * loaded via Theatre's own repository (same module, direct — the T.1
 * precedent) for the eligibility gate and the denormalized context copy,
 * plus the ADR-133-style per-case-facility refinement (the
 * CreateSurgicalCaseHandler precedent).
 */
final class CreateOperativeNoteHandler
{
    public function __construct(
        private readonly OperativeNoteRepository $notes,
        private readonly SurgicalCaseRepository $cases,
        private readonly OperativeNoteLifecycle $lifecycle,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(CreateOperativeNote $command): OperativeNote
    {
        $case = $this->cases->getById($command->caseId);

        $this->assertActorScopedToCaseFacility((string) $case->facility_id, $command->noteId);

        $this->lifecycle->assertCaseEligibleForNote((string) $case->id, (string) $case->status);

        $note = OperativeNote::create(
            id: $command->noteId,
            tenantId: (string) $case->tenant_id,
            facilityId: (string) $case->facility_id,
            caseId: (string) $case->id,
            patientId: (string) $case->patient_id,
            encounterId: (string) $case->encounter_id,
            content: $command->content,
            createdByUserId: $command->actorId,
        );

        try {
            $this->notes->save($note);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'thr_operative_notes_tenant_id_case_id_unique')) {
                throw OperativeNoteAlreadyExistsForCase::forCase((string) $case->id);
            }

            throw $e;
        }

        return $note;
    }

    private function assertActorScopedToCaseFacility(string $facilityId, string $noteId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreateOperativeNote dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'theatre.operative_notes.author',
            $this->scope->effectivePermissions($user, $facilityId),
            true,
        )) {
            throw NotFound::aggregate(OperativeNote::class, $noteId);
        }
    }
}
