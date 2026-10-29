<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\AttendanceExceptionType;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class CloseAttendanceSession implements AuthorizesAgainst
{
    public string $endEventId;

    public CarbonImmutable $endedAt;

    public function __construct(
        public string $sessionId,
        string|CarbonImmutable $endedAt,
        ?string $endEventId = null,
        public ?AttendanceExceptionType $exception = null,
    ) {
        $this->endEventId = $endEventId ?? (string) Str::uuid();
        $this->endedAt = is_string($endedAt) ? CarbonImmutable::parse($endedAt) : $endedAt;
    }

    public function policyAbility(): string
    {
        return 'close';
    }

    public function subjectId(): string
    {
        return $this->sessionId;
    }
}
