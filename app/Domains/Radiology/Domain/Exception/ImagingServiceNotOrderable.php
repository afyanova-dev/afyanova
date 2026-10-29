<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateImagingOrder` named a `rad_imaging_services` row that cannot be
 * ordered through the internal Rad.1 path: inactive or DISABLED (§137 / §209
 * Inv 30), `referral_only` (§84 / §138 / §209 Inv 32 — the referral routing
 * path is deferred to a later increment), its parent modality is SUSPENDED or
 * inactive (§139 / §198 / §209 Inv 31), or its (or its modality's)
 * `required_capability_code` is not enabled at the ordering facility (§18,
 * §197; the CP.1 `ProcedureCapabilityUnavailable` / Lab.1 `LabTestNotOrderable`
 * precedent). Maps to HTTP 409.
 */
final class ImagingServiceNotOrderable extends DomainException
{
    public static function unavailable(string $serviceId): self
    {
        return new self(sprintf(
            'Imaging service [%s] is inactive or DISABLED and cannot be ordered.',
            $serviceId,
        ));
    }

    public static function referralOnly(string $serviceId): self
    {
        return new self(sprintf(
            'Imaging service [%s] is referral-only — internal ordering is not available in Rad.1.',
            $serviceId,
        ));
    }

    public static function modalityNotOperational(string $serviceId, string $modalityCode): self
    {
        return new self(sprintf(
            'Imaging service [%s] runs on modality [%s], which is SUSPENDED or inactive.',
            $serviceId,
            $modalityCode,
        ));
    }

    public static function capabilityMissing(string $serviceId, string $capabilityCode, string $facilityId): self
    {
        return new self(sprintf(
            'Imaging service [%s] requires capability [%s], which is not enabled at facility [%s].',
            $serviceId,
            $capabilityCode,
            $facilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'imaging_service_not_orderable';
    }
}
