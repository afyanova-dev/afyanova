<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\AttendanceSessionRepository;

/**
 * Handles CloseAttendanceSession command (25-hr-and-workforce-domain.md §6).
 */
final class CloseAttendanceSessionHandler
{
    public function __construct(
        private readonly AttendanceSessionRepository $sessions,
    ) {}

    public function __invoke(CloseAttendanceSession $command): void
    {
        $session = $this->sessions->getByIdForUpdate($command->sessionId);

        $session->close(
            $command->endedAt,
            $command->endEventId,
            $command->exception
        );

        $this->sessions->save($session);
    }
}
