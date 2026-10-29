<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A `DiscontinuePrescribingDecision` or `CorrectPrescribingDecision` was
 * dispatched against a decision it cannot legally transition (ADR-143 D-J —
 * both require status ACTIVE; SUPERSEDED and DISCONTINUED are terminal).
 * Maps to HTTP 409 (IC §15).
 */
final class IllegalPrescribingDecisionStatusTransition extends DomainException
{
    public static function discontinue(string $current): self
    {
        return new self(sprintf(
            'DiscontinuePrescribingDecision is not a legal transition from status [%s]. Discontinuation requires ACTIVE.',
            $current,
        ));
    }

    public static function correct(string $current): self
    {
        return new self(sprintf(
            'CorrectPrescribingDecision is not a legal transition from status [%s]. Correcting a decision requires ACTIVE; a DISCONTINUED decision is terminal — record a new decision instead.',
            $current,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_prescribing_decision_status_transition';
    }
}
