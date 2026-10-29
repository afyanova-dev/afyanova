<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

/**
 * Where a coverage decision came from (21-insurance-and-payer-domain.md §166;
 * ADR-200 D-A / D-F): LOCAL_POLICY | PAYER_API | PAYER_PORTAL | MANUAL |
 * IMPORTED.
 *
 * Ins.3 only ever emits `LOCAL_POLICY` — evaluation is local, rule-based
 * (ADR-198 D-F). PAYER_API / PAYER_PORTAL / IMPORTED land with the payer-API
 * sync path (PHASE 13). Backend-only string-constant holder.
 */
final class CoverageSource
{
    public const LOCAL_POLICY = 'LOCAL_POLICY';

    public const PAYER_API = 'PAYER_API';

    public const PAYER_PORTAL = 'PAYER_PORTAL';

    public const MANUAL = 'MANUAL';

    public const IMPORTED = 'IMPORTED';

    /** @var list<string> */
    public const ALL = [
        self::LOCAL_POLICY,
        self::PAYER_API,
        self::PAYER_PORTAL,
        self::MANUAL,
        self::IMPORTED,
    ];
}
