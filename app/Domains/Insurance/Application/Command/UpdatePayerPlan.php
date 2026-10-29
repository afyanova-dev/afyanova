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
 * Change a plan's display name and/or effective window (ADR-198 D-H).
 * Per-subject `manage`. `code` / `payer_id` are SET-ONCE — not here. A null
 * argument leaves that field unchanged.
 */
final readonly class UpdatePayerPlan implements Auditable, AuthorizesAgainst
{
    public ?string $name;

    public ?CarbonImmutable $effectiveFrom;

    public ?CarbonImmutable $effectiveTo;

    public function __construct(
        public string $planId,
        public string $actorId,
        ?string $name = null,
        ?string $effectiveFrom = null,
        ?string $effectiveTo = null,
    ) {
        if (! Str::isUuid($planId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdatePayerPlan ids must be UUIDs (IC §3).');
        }

        if ($name !== null && trim($name) === '') {
            throw new InvalidArgumentException('UpdatePayerPlan::$name, when given, must not be blank.');
        }

        $from = self::parseDate($effectiveFrom, 'effectiveFrom');
        $to = self::parseDate($effectiveTo, 'effectiveTo');
        if ($from !== null && $to !== null && $from->gt($to)) {
            throw new InvalidArgumentException('UpdatePayerPlan::$effectiveFrom must not be after $effectiveTo.');
        }

        $this->name = $name !== null ? trim($name) : null;
        $this->effectiveFrom = $from;
        $this->effectiveTo = $to;
    }

    private static function parseDate(?string $value, string $field): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException("UpdatePayerPlan::\${$field} must be a valid date.");
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->planId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.payer_plan_updated',
            actorId: $this->actorId,
            targetType: 'payer_plan',
            targetId: $this->planId,
        );
    }
}
