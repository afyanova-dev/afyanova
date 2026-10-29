<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Adapter;

use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Port\DocumentStoragePort;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class FileSystemDocumentStorageAdapter implements DocumentStoragePort
{
    private string $disk;

    public function __construct(string $disk = 'local')
    {
        $this->disk = $disk;
    }

    public function put(string $storageKey, string $contents): void
    {
        $written = Storage::disk($this->disk)->put($storageKey, $contents);
        if (! $written) {
            throw new RuntimeException("Failed to write document bytes to storage key: {$storageKey}");
        }
    }

    public function get(string $storageKey): string
    {
        $content = Storage::disk($this->disk)->get($storageKey);
        if ($content === null) {
            throw new DocumentNotFoundException("Document content not found at storage key: {$storageKey}");
        }

        return $content;
    }

    public function exists(string $storageKey): bool
    {
        return Storage::disk($this->disk)->exists($storageKey);
    }

    public function delete(string $storageKey): bool
    {
        return Storage::disk($this->disk)->delete($storageKey);
    }

    public function shred(string $storageKey): bool
    {
        $disk = Storage::disk($this->disk);

        if (! $disk->exists($storageKey)) {
            return true;
        }

        $size = $disk->size($storageKey);
        // Overwrite file contents with zeros for defensible physical destruction
        $zeros = str_repeat("\0", min((int) $size, 65536));
        $disk->put($storageKey, $zeros);

        return $disk->delete($storageKey);
    }
}
