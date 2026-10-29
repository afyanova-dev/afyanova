<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Safety checklist has fewer completed entries than the transition's floor
 * requires (17-theatre-domain.md §51 Checklist Completion; ADR-150 D-G — an
 * interim safety floor, not a final clinical policy). Maps to HTTP 409.
 */
final class ChecklistGateNotSatisfied extends DomainException
{
    public static function forTransition(string $action, int $required, int $actual): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] — safety checklist requires at least %d completed entries, found %d.',
            $action,
            $required,
            $actual,
        ));
    }

    public function errorCode(): string
    {
        return 'checklist_gate_not_satisfied';
    }
}
