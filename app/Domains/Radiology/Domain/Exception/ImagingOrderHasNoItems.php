<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateImagingOrder` was dispatched with an empty service list, or
 * `SubmitImagingOrder` was attempted on an order that has no active items
 * (15-radiology-domain.md §18–§19; ADR-165 D-G). An order with no services is
 * not a request. Maps to HTTP 409.
 */
final class ImagingOrderHasNoItems extends DomainException
{
    public static function forCreate(): self
    {
        return new self('CreateImagingOrder requires at least one imaging service.');
    }

    public static function forSubmit(string $orderId): self
    {
        return new self(sprintf('Imaging order [%s] has no active items to submit.', $orderId));
    }

    public function errorCode(): string
    {
        return 'imaging_order_has_no_items';
    }
}
