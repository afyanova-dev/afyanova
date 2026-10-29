<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateLabOrder` was dispatched with an empty test list, or `SubmitLabOrder`
 * was attempted on an order that has no active items (14-laboratory-domain.md
 * §21, §23; ADR-157 D-F). An order with no tests is not a request. Maps to
 * HTTP 409.
 */
final class LabOrderHasNoItems extends DomainException
{
    public static function forCreate(): self
    {
        return new self('CreateLabOrder requires at least one test.');
    }

    public static function forSubmit(string $orderId): self
    {
        return new self(sprintf('Laboratory order [%s] has no active items to submit.', $orderId));
    }

    public function errorCode(): string
    {
        return 'lab_order_has_no_items';
    }
}
