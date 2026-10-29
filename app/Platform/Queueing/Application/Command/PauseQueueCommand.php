<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class PauseQueueCommand implements AuthorizesAgainst
{
    public function __construct(
        public string $queueId,
        public string $reason,
    ) {}

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->queueId;
    }
}
