<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A non-terminal Pharmacy Order already exists for this prescription
 * (16-pharmacy-domain.md §21 / §23; ADR-182 D-B / D-D — one non-terminal order
 * per prescription, the partial unique index). Maps to HTTP 409.
 */
final class PharmacyOrderAlreadyExists extends DomainException
{
    public static function forPrescription(string $prescriptionId): self
    {
        return new self(sprintf(
            'A non-terminal Pharmacy Order already exists for prescription [%s].',
            $prescriptionId,
        ));
    }

    public function errorCode(): string
    {
        return 'pharmacy_order_already_exists';
    }
}
