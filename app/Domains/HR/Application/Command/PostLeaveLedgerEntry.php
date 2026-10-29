<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\LeaveLedgerKind;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class PostLeaveLedgerEntry implements AuthorizesAgainst
{
    public string $ledgerEntryId;

    public LeaveLedgerKind $kind;

    public CarbonImmutable $effectiveDate;

    public function __construct(
        public string $staffId,
        public string $leaveTypeId,
        LeaveLedgerKind|string $kind,
        public float $delta,
        string|CarbonImmutable $effectiveDate,
        public string $postedBy,
        public ?string $sourceRef = null,
        public ?string $reason = null,
        ?string $ledgerEntryId = null,
    ) {
        $this->ledgerEntryId = $ledgerEntryId ?? (string) Str::uuid();
        $this->kind = is_string($kind) ? LeaveLedgerKind::from($kind) : $kind;
        $this->effectiveDate = is_string($effectiveDate) ? CarbonImmutable::parse($effectiveDate) : $effectiveDate;
    }

    public function policyAbility(): string
    {
        return 'post';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
