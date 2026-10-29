<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\AttendanceExceptionType;
use App\Shared\Bus\AuthorizesAgainst;

final readonly class ReconcileAttendanceSession implements AuthorizesAgainst
{
    public function __construct(
        public string $sessionId,
        public string $reconciledBy,
        public string $note,
        public ?int $adjustedWorkedMinutes = null,
        public ?AttendanceExceptionType $adjustedException = null,
    ) {}

    public function policyAbility(): string
    {
        return 'reconcile';
    }

    public function subjectId(): string
    {
        return $this->sessionId;
    }
}
