<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\LeaveLedgerEntry;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: LeaveLedgerEntry::class)]
final readonly class GetStaffLeaveBalances
{
    public function __construct(
        public string $staffId,
    ) {}
}
