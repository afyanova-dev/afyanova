<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\AttendanceSessionRepository;

/**
 * Handles ReconcileAttendanceSession command (25-hr-and-workforce-domain.md §6).
 */
final class ReconcileAttendanceSessionHandler
{
    public function __construct(
        private readonly AttendanceSessionRepository $sessions,
    ) {}

    public function __invoke(ReconcileAttendanceSession $command): void
    {
        $session = $this->sessions->getByIdForUpdate($command->sessionId);

        $session->reconcile(
            $command->reconciledBy,
            $command->note,
            $command->adjustedWorkedMinutes,
            $command->adjustedException
        );

        $this->sessions->save($session);
    }
}
