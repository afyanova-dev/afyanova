<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\AnaesthesiaRecordRepository;
use App\Domains\Theatre\Domain\Service\AnaesthesiaRecordLifecycle;

final class SignAnaesthesiaRecordHandler
{
    public function __construct(
        private readonly AnaesthesiaRecordRepository $records,
        private readonly AnaesthesiaRecordLifecycle $lifecycle,
    ) {}

    public function __invoke(SignAnaesthesiaRecord $command): void
    {
        $record = $this->records->getById($command->recordId);

        $this->lifecycle->assertCanSign((string) $record->status);

        $record->sign($command->actorId);

        $this->records->save($record);
    }
}
