<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application;

use App\Domains\Patient\Infrastructure\Reader\RegistrationDraftReader;
use App\Domains\Patient\Infrastructure\Writer\RegistrationDraftWriter;
use JsonException;

final class RegistrationDraftService
{
    public function __construct(
        private readonly RegistrationDraftReader $reader,
        private readonly RegistrationDraftWriter $writer,
    ) {}

    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException
     */
    public function find(string $tenantId, string $facilityId, string $userId): ?array
    {
        return $this->reader->find($tenantId, $facilityId, $userId);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{savedAt: string, expiresAt: string}
     *
     * @throws JsonException
     */
    public function save(string $tenantId, string $facilityId, string $userId, array $values): array
    {
        return $this->writer->save($tenantId, $facilityId, $userId, $values);
    }

    public function delete(string $tenantId, string $facilityId, string $userId): void
    {
        $this->writer->delete($tenantId, $facilityId, $userId);
    }
}
