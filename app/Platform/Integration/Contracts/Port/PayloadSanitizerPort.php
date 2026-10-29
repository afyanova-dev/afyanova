<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Port;

interface PayloadSanitizerPort
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array;
}
