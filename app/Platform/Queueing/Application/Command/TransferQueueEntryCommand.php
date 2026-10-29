<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class TransferQueueEntryCommand implements AuthorizesAgainst
{
    public function __construct(
        public string $entryId,
        public string $destinationQueueId,
        public ?string $sourceOrderRef = null,
        public ?string $orderType = null,
        public ?string $reason = null,
    ) {}

    public function policyAbility(): string
    {
        return 'transfer';
    }

    public function subjectId(): string
    {
        return $this->entryId;
    }
}
