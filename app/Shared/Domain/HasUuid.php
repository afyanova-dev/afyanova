<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * Provides UUIDv7 for Eloquent models (IMPLEMENTATION-CONTRACTS.md §4.1).
 */
trait HasUuid
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
