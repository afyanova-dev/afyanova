<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application;

use App\Domains\Authentication\Infrastructure\Writer\AdminBootstrapCompletionWriter;

final class AdminBootstrapCompletionService
{
    public function __construct(private readonly AdminBootstrapCompletionWriter $writer) {}

    public function complete(string $tokenHash, string $password): bool
    {
        return $this->writer->complete($tokenHash, $password);
    }
}
