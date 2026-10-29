<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\OperativeNoteSummary;
use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Domains\Theatre\Domain\OperativeNoteReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetOperativeNoteForCaseHandler
{
    public function __construct(
        private readonly OperativeNoteReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetOperativeNoteForCase $query): ?OperativeNoteSummary
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetOperativeNoteForCase dispatched without an authenticated user (IC §6.1).');
        }

        $summary = $this->reader->forCase($query->caseId);

        if ($summary === null) {
            return null; // no note yet — the #[ProtectedQuery] `view` gate already confirmed theatre.access
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(OperativeNote::class, $query->caseId);
        }

        return $summary;
    }
}
