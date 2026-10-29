<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\AttendanceExceptionType;
use App\Shared\Bus\AuthorizesAgainst;

final readonly class RaiseAttendanceException implements AuthorizesAgainst
{
    public AttendanceExceptionType $exceptionType;

    public function __construct(
        public string $sessionId,
        AttendanceExceptionType|string $exceptionType,
    ) {
        $this->exceptionType = is_string($exceptionType) ? AttendanceExceptionType::from($exceptionType) : $exceptionType;
    }

    public function policyAbility(): string
    {
        return 'raiseException';
    }

    public function subjectId(): string
    {
        return $this->sessionId;
    }
}
