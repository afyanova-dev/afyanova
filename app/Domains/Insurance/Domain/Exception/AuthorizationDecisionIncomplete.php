<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ApproveAuthorization` missed required decision attributes: the external
 * authorization number, validTo date, or an invalid validity window
 * (21-insurance-and-payer-domain.md §60 / §276 Inv 23; ADR-202 D-B).
 * Maps to HTTP 422.
 */
final class AuthorizationDecisionIncomplete extends DomainException
{
    public static function missingExternalNumber(): self
    {
        return new self('ApproveAuthorization requires external authorization number.');
    }

    public static function missingValidTo(): self
    {
        return new self('ApproveAuthorization requires valid_to date.');
    }

    public static function invalidWindow(): self
    {
        return new self('ApproveAuthorization valid_from must not be after valid_to.');
    }

    public function errorCode(): string
    {
        return 'authorization_decision_incomplete';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
