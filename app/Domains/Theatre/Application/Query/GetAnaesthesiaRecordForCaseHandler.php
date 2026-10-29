<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\AnaesthesiaRecordSummary;
use App\Domains\Theatre\Domain\AnaesthesiaRecordReader;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetAnaesthesiaRecordForCaseHandler
{
    public function __construct(
        private readonly AnaesthesiaRecordReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetAnaesthesiaRecordForCase $query): ?AnaesthesiaRecordSummary
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetAnaesthesiaRecordForCase dispatched without an authenticated user (IC §6.1).');
        }

        $summary = $this->reader->forCase($query->caseId);

        if ($summary === null) {
            return null; // no record yet — the #[ProtectedQuery] `view` gate already confirmed theatre.access
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(AnaesthesiaRecord::class, $query->caseId);
        }

        return $summary;
    }
}
