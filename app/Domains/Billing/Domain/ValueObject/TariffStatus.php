<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * Status set for a tariff row (20-billing-and-payments-domain.md §19 / §20;
 * ADR-188 D-B):
 *
 *   CURRENT     — the open row (effective_to IS NULL).
 *   SUPERSEDED  — closed by a later SetTariff.
 *   ENDED       — closed by EndTariff, no replacement (the scope is then
 *                 unpriced onward).
 *
 * Backend-only string-constant holder.
 */
final class TariffStatus
{
    public const CURRENT = 'CURRENT';

    public const SUPERSEDED = 'SUPERSEDED';

    public const ENDED = 'ENDED';

    /** @var list<string> */
    public const ALL = [self::CURRENT, self::SUPERSEDED, self::ENDED];

    /** The `resolvedScope` values on a `TariffResolution` (ADR-188 D-D). */
    public const SCOPE_FACILITY = 'FACILITY';

    public const SCOPE_TENANT_DEFAULT = 'TENANT_DEFAULT';

    public const SCOPE_NONE = 'NONE';
}
