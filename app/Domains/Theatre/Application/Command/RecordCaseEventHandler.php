<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\CaseEventRepository;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForCaseEvent;
use App\Domains\Theatre\Domain\Model\CaseEvent;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `RecordCaseEvent` (ADR-156 D-E). `CaseEventRepository` is
 * injected FIRST so the bus resolves `CaseEventPolicy` (IC §5.1). The
 * SurgicalCase is loaded via Theatre's own repository for the eligibility
 * gate + the denormalised context copy + the ADR-133 per-case-facility
 * refinement.
 */
final class RecordCaseEventHandler
{
    /** @var list<string> Nothing has happened yet to time in these states. */
    private const CASE_INELIGIBLE = [SurgicalCase::STATUS_REQUESTED, SurgicalCase::STATUS_PLANNED];

    public function __construct(
        private readonly CaseEventRepository $events,
        private readonly SurgicalCaseRepository $cases,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(RecordCaseEvent $command): CaseEvent
    {
        $case = $this->cases->getById($command->caseId);

        $this->assertActorScopedToCaseFacility((string) $case->facility_id, $command->caseEventId);

        if (in_array((string) $case->status, self::CASE_INELIGIBLE, true)) {
            throw SurgicalCaseNotEligibleForCaseEvent::forStatus((string) $case->id, (string) $case->status);
        }

        $event = CaseEvent::record(
            id: $command->caseEventId,
            tenantId: (string) $case->tenant_id,
            facilityId: (string) $case->facility_id,
            caseId: (string) $case->id,
            eventType: strtoupper(trim($command->eventType)),
            eventAt: CarbonImmutable::parse($command->eventAt),
            note: $command->note !== null ? trim($command->note) : null,
            recordedByUserId: $command->actorId,
        );

        $this->events->save($event);

        return $event;
    }

    private function assertActorScopedToCaseFacility(string $facilityId, string $caseEventId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordCaseEvent dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('theatre.case_events.record', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(CaseEvent::class, $caseEventId);
        }
    }
}
