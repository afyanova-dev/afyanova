<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\AnaesthesiaRecordRepository;
use App\Domains\Theatre\Domain\Service\AnaesthesiaRecordLifecycle;
use Carbon\CarbonImmutable;

final class UpdateAnaesthesiaContentHandler
{
    public function __construct(
        private readonly AnaesthesiaRecordRepository $records,
        private readonly AnaesthesiaRecordLifecycle $lifecycle,
    ) {}

    public function __invoke(UpdateAnaesthesiaContent $command): void
    {
        $record = $this->records->getById($command->recordId);

        $this->lifecycle->assertCanUpdateContent((string) $record->status);

        $record->updateContent(
            $command->content,
            $command->startedAt !== null ? CarbonImmutable::parse($command->startedAt) : null,
            $command->endedAt !== null ? CarbonImmutable::parse($command->endedAt) : null,
            $command->outcome !== null ? trim($command->outcome) : null,
        );

        $this->records->save($record);
    }
}
