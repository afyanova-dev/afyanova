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

final class GetAnaesthesiaRecordHandler
{
    public function __construct(
        private readonly AnaesthesiaRecordReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetAnaesthesiaRecord $query): AnaesthesiaRecordSummary
    {
        $summary = $this->reader->summary($query->anaesthesiaRecordId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetAnaesthesiaRecord dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('theatre.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(AnaesthesiaRecord::class, $query->anaesthesiaRecordId);
        }

        return $summary;
    }
}
