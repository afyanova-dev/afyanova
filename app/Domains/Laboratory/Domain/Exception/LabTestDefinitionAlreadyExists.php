<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `laboratory:define-test define` named a `code` that already belongs to an
 * ACTIVE definition in the tenant (14-laboratory-domain.md §16; ADR-157 D-C —
 * the CP.1 catalog `code` tenant-unique-among-active rule). A retired code may
 * be reused. Maps to HTTP 409.
 */
final class LabTestDefinitionAlreadyExists extends DomainException
{
    public static function forCode(string $tenantId, string $code): self
    {
        return new self(sprintf(
            'An active laboratory test definition with code [%s] already exists in tenant [%s].',
            $code,
            $tenantId,
        ));
    }

    public function errorCode(): string
    {
        return 'lab_test_definition_already_exists';
    }
}
