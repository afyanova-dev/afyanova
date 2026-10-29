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
 * Create a plan under a payer (21-insurance-and-payer-domain.md §11, §12;
 * ADR-198 D-H). Class-level `manage` (`insurance.plans.manage`).
 *
 * `code` is SET-ONCE and unique per payer → `PayerPlanCodeTaken` on the race.
 * The payer must exist in-tenant → `PayerNotFound`. `effectiveFrom` /
 * `effectiveTo` are stored but not enforced in Ins.1. Benefit configuration is
 * Ins.3 — not here.
 */
final readonly class CreatePayerPlan implements Auditable, AuthorizesAgainst
{
    public string $code;

    public string $name;

    public ?CarbonImmutable $effectiveFrom;

    public ?CarbonImmutable $effectiveTo;

    public function __construct(
        public string $planId,
        public string $actorId,
        public string $payerId,
        string $code,
        string $name,
        ?string $effectiveFrom = null,
        ?string $effectiveTo = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['planId' => $planId, 'actorId' => $actorId, 'payerId' => $payerId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreatePayerPlan::\${$field} must be a UUID (IC §3).");
            }
        }

        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 64) {
            throw new InvalidArgumentException('CreatePayerPlan::$code must be 1–64 characters.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('CreatePayerPlan::$name is required.');
        }

        $from = self::parseDate($effectiveFrom, 'effectiveFrom');
        $to = self::parseDate($effectiveTo, 'effectiveTo');
        if ($from !== null && $to !== null && $from->gt($to)) {
            throw new InvalidArgumentException('CreatePayerPlan::$effectiveFrom must not be after $effectiveTo.');
        }

        $this->code = $code;
        $this->name = $name;
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
            throw new InvalidArgumentException("CreatePayerPlan::\${$field} must be a valid date.");
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.payer_plan_created',
            actorId: $this->actorId,
            targetType: 'payer_plan',
            targetId: $this->planId,
            metadata: ['payer_id' => $this->payerId, 'code' => $this->code],
        );
    }
}
