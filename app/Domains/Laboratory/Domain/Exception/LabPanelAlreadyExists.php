<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `laboratory:define-panel define` named a `code` that already belongs to an
 * ACTIVE panel in the tenant (14-laboratory-domain.md §16; ADR-162 D-C). Maps
 * to HTTP 409.
 */
final class LabPanelAlreadyExists extends DomainException
{
    public static function forCode(string $tenantId, string $code): self
    {
        return new self(sprintf('An active laboratory panel with code [%s] already exists in tenant [%s].', $code, $tenantId));
    }

    public function errorCode(): string
    {
        return 'lab_panel_already_exists';
    }
}
