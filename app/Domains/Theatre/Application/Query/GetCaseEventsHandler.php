<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\CaseEventSummary;
use App\Domains\Theatre\Domain\CaseEventReader;
use App\Domains\Theatre\Domain\Model\CaseEvent;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetCaseEventsHandler
{
    public function __construct(
        private readonly CaseEventReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<CaseEventSummary>
     */
    public function __invoke(GetCaseEvents $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetCaseEvents dispatched without an authenticated user (IC §6.1).');
        }

        $events = $this->reader->forCase($query->caseId);

        if ($events === []) {
            return []; // the #[ProtectedQuery] `view` gate already confirmed theatre.access
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $events[0]->facilityId), true)) {
            throw NotFound::aggregate(CaseEvent::class, $query->caseId);
        }

        return $events;
    }
}
