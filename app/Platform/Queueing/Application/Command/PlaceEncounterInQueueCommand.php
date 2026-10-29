<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class PlaceEncounterInQueueCommand implements AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $patientId,
        public string $queueId,
        public string $priority = 'ROUTINE',
        public ?string $sourceOrderRef = null,
        public ?string $orderType = null,
    ) {}

    public function policyAbility(): string
    {
        return 'placeIn';
    }

    public function subjectId(): string
    {
        return $this->queueId;
    }
}
