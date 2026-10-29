<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Money;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Open a cashier's till session with an opening float
 * (20-billing-and-payments-domain.md §153; ADR-191 C6-A). Class-level `manage`
 * (`billing.cash_sessions.manage`). ONE live session per (cashier, facility) —
 * `CashSessionAlreadyOpen` otherwise. The cashier is the acting user.
 */
final readonly class OpenCashSession implements Auditable, AuthorizesAgainst
{
    /** Validated + normalised to a scale-2 numeric string via {@see Money::of()}. */
    public string $openingFloat;

    public function __construct(
        public string $actorId,
        public string $cashSessionId,
        public string $facilityId,
        string $openingFloat,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['actorId' => $actorId, 'cashSessionId' => $cashSessionId, 'facilityId' => $facilityId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("OpenCashSession::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! is_numeric($openingFloat) || bccomp(Money::of($openingFloat), '0', 2) < 0) {
            throw new InvalidArgumentException('OpenCashSession::$openingFloat must be a non-negative money value (§153).');
        }

        $this->openingFloat = Money::of($openingFloat);
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
            action: 'billing.cash_session_opened',
            actorId: $this->actorId,
            targetType: 'cash_session',
            targetId: $this->cashSessionId,
            metadata: ['facility_id' => $this->facilityId, 'opening_float' => $this->openingFloat],
        );
    }
}
