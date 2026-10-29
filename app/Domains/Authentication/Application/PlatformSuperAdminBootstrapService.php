<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application;

use App\Domains\Authentication\Infrastructure\Writer\PlatformSuperAdminBootstrapWriter;

final class PlatformSuperAdminBootstrapService
{
    public function __construct(private readonly PlatformSuperAdminBootstrapWriter $writer) {}

    public function complete(string $tokenHash, string $password): void
    {
        $this->writer->complete($tokenHash, $password);
    }
}
