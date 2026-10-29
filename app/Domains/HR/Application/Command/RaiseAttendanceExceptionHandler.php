<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\AttendanceSessionRepository;

/**
 * Handles RaiseAttendanceException command (25-hr-and-workforce-domain.md §6).
 */
final class RaiseAttendanceExceptionHandler
{
    public function __construct(
        private readonly AttendanceSessionRepository $sessions,
    ) {}

    public function __invoke(RaiseAttendanceException $command): void
    {
        $session = $this->sessions->getByIdForUpdate($command->sessionId);

        $session->raiseException($command->exceptionType);

        $this->sessions->save($session);
    }
}
