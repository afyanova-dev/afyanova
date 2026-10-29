<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Domains\Clinical\Domain\Enum\ClinicalProcedureStatus;
use App\Shared\Domain\DomainException;

/**
 * A procedure lifecycle command was dispatched against a procedure it cannot
 * legally act on (13-clinical-procedures-domain.md §32, §138; ADR-146 D-F).
 * Validated by `Clinical\Domain\Service\ClinicalProcedureLifecycle` before any
 * mutation. Maps to HTTP 409 (IC §15).
 */
final class IllegalProcedureTransition extends DomainException
{
    public static function for(string $command, ClinicalProcedureStatus $current): self
    {
        return new self(sprintf(
            '%s is not a legal transition from status %s.',
            $command,
            $current->value,
        ));
    }

    /**
     * `MarkProcedureReady` was attempted on a procedure whose definition does
     * NOT require a readiness check (ADR-146 D-F) — the direct
     * `AUTHORIZED → IN_PROGRESS` path applies instead.
     */
    public static function readinessNotRequired(string $command): self
    {
        return new self(sprintf(
            '%s is not legal — this procedure\'s definition does not require a readiness check.',
            $command,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_procedure_transition';
    }
}
