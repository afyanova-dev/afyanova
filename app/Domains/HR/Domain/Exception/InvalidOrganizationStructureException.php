<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class InvalidOrganizationStructureException extends DomainException
{
    public static function facilityNotFound(string $facilityId): self
    {
        return new self(sprintf('Organization facility [%s] does not exist.', $facilityId));
    }

    public static function departmentNotFound(string $facilityId, string $departmentId): self
    {
        return new self(sprintf('Department [%s] does not exist in facility [%s].', $departmentId, $facilityId));
    }

    public static function unitNotFound(string $facilityId, string $unitId): self
    {
        return new self(sprintf('Unit [%s] does not exist in facility [%s].', $unitId, $facilityId));
    }

    public function errorCode(): string
    {
        return 'invalid_organization_structure';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
