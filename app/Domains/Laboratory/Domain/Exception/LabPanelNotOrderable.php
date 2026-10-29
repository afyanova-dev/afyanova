<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateLabOrder` named a panel that cannot be ordered — inactive, empty, or
 * unknown (14-laboratory-domain.md §14–§15; ADR-162 D-D). Maps to HTTP 409.
 */
final class LabPanelNotOrderable extends DomainException
{
    public static function inactive(string $panelId): self
    {
        return new self(sprintf('Laboratory panel [%s] is inactive and cannot be ordered.', $panelId));
    }

    public static function empty(string $panelId): self
    {
        return new self(sprintf('Laboratory panel [%s] has no member tests.', $panelId));
    }

    public function errorCode(): string
    {
        return 'lab_panel_not_orderable';
    }
}
