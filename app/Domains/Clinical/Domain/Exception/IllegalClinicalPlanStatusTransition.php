<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A `CloseClinicalPlan` was dispatched against a plan it cannot legally
 * transition (ADR-143 D-E — closing requires status ACTIVE).
 * Maps to HTTP 409 (IC §15).
 */
final class IllegalClinicalPlanStatusTransition extends DomainException
{
    public static function close(string $current): self
    {
        return new self(sprintf(
            'CloseClinicalPlan is not a legal transition from status [%s]. Closing requires ACTIVE.',
            $current,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_clinical_plan_status_transition';
    }
}
