<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\LeaveLedgerEntry;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;

#[ProtectedQuery(ability: 'view', subjectType: LeaveLedgerEntry::class)]
final readonly class GetLeaveLedger
{
    public ?CarbonImmutable $from;

    public ?CarbonImmutable $to;

    public function __construct(
        public string $staffId,
        public ?string $leaveTypeId = null,
        string|CarbonImmutable|null $from = null,
        string|CarbonImmutable|null $to = null,
    ) {
        $this->from = is_string($from) ? CarbonImmutable::parse($from) : $from;
        $this->to = is_string($to) ? CarbonImmutable::parse($to) : $to;
    }
}
