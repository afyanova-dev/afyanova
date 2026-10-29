<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

/**
 * The result of a successful `RequestProcedure` (the `RecordDiagnosisResult`
 * precedent) — the client-minted procedure id and its allocated human-readable
 * `procedureNumber`, echoed back for confirmation.
 */
final readonly class RequestProcedureResult
{
    public function __construct(
        public string $procedureId,
        public string $procedureNumber,
    ) {}
}
