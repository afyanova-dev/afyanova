<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

final class IllegalLabExecutionTransition extends DomainException
{
    public static function fromStatus(string $status): self
    {
        return new self("A laboratory execution in status [{$status}] cannot be changed.");
    }

    public static function fromMaterialUseStatus(string $status): self
    {
        return new self("A laboratory material use in status [{$status}] cannot be changed.");
    }

    public function errorCode(): string
    {
        return 'illegal_lab_execution_transition';
    }
}
