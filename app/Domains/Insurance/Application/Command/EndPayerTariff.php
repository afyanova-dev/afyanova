<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * End an open payer tariff with no replacement (ADR-203).
 * Per-subject `manage` (`insurance.tariffs.manage`).
 */
final readonly class EndPayerTariff implements Auditable, AuthorizesAgainst
{
    public CarbonImmutable $effectiveTo;

    public function __construct(
        public string $tariffId,
        public string $actorId,
        string $effectiveTo,
    ) {
        if (! Str::isUuid($tariffId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('EndPayerTariff ids must be UUIDs (IC §3).');
        }

        try {
            $this->effectiveTo = CarbonImmutable::parse($effectiveTo)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('EndPayerTariff::$effectiveTo must be a valid date.');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->tariffId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.payer_tariff_ended',
            actorId: $this->actorId,
            targetType: 'payer_tariff',
            targetId: $this->tariffId,
            reason: $this->effectiveTo->toDateString(),
        );
    }
}
