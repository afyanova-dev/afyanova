<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Contracts\Dto\AnaesthesiaRecordSummary;
use App\Domains\Theatre\Domain\AnaesthesiaRecordReader;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetAnaesthesiaRecordForCaseHandler
{
    public function __construct(
        private readonly AnaesthesiaRecordReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
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

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'theatre.access')) {
            throw NotFound::aggregate(AnaesthesiaRecord::class, $query->caseId);
        }

        return $summary;
    }
}
