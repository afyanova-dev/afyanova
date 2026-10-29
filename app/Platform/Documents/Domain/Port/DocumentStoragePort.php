<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Port;

interface DocumentStoragePort
{
    public function put(string $storageKey, string $contents): void;

    public function get(string $storageKey): string;

    public function exists(string $storageKey): bool;

    public function delete(string $storageKey): bool;

    /**
     * F13: Cryptographically overwrites content before deletion to guarantee defensible physical shredding.
     */
    public function shred(string $storageKey): bool;
}
