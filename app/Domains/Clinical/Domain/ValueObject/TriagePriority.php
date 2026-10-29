<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The clinical triage priority a triage assessment produces
 * (23-queueing-and-patient-flow.md §21; ADR-105, ADR-145 D-E).
 *
 * A CLOSED coded set — the one behaviour-carrying field of a `TriageAssessment`
 * (the clinical fact Queueing & Patient Flow will consume in PHASE 12 to order
 * downstream queues; ADR-105 — "Queueing never computes clinical urgency
 * itself"). Behaviour-NEUTRAL *within* Clinical: nothing in
 * `App\Domains\Clinical\Domain\Service` / `Authorization` branches lifecycle,
 * authorization or validation off it (the ADR-139 D3 discipline; ADR-145 O-2).
 *
 * Backend-only string-constant holder (NOT a PHP enum, NOT `#[TypeScript]` —
 * ADR-145 D-Q). A Queueing / frontend coded contract is a PHASE 12 / PHASE 16
 * concern added by that consumer's increment. `acuity` and `consciousness`, by
 * contrast, are OPEN coded strings (no scale enum — ADR-145 O-1).
 */
final class TriagePriority
{
    public const EMERGENCY = 'EMERGENCY';

    public const URGENT = 'URGENT';

    public const HIGH = 'HIGH';

    public const NORMAL = 'NORMAL';

    public const LOW = 'LOW';

    /** @var list<string> */
    public const ALL = [
        self::EMERGENCY,
        self::URGENT,
        self::HIGH,
        self::NORMAL,
        self::LOW,
    ];

    public static function assert(string $value): string
    {
        $normalized = strtoupper(trim($value));

        if (! in_array($normalized, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid TriagePriority [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $normalized;
    }

    public static function isValid(string $value): bool
    {
        return in_array(strtoupper(trim($value)), self::ALL, true);
    }
}
