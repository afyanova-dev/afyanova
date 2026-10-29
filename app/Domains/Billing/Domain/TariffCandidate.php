<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

/**
 * A single tariff row that matches a resolution date, reduced to the scalars
 * `TariffResolver` needs (ADR-188 D-E). Built by the reader from a date-filtered
 * `Tariff` query; passed to the pure resolver so it does no I/O.
 *
 * @internal Billing Domain
 */
final readonly class TariffCandidate
{
    /**
     * @param  numeric-string  $amount  scale-2
     */
    public function __construct(
        public string $tariffRowId,
        public string $amount,
        public string $currency,
        public string $effectiveFrom,
    ) {}
}
