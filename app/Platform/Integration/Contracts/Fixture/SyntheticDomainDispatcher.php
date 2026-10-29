<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Fixture;

use App\Platform\Integration\Contracts\Port\DomainDispatcherPort;

final class SyntheticDomainDispatcher implements DomainDispatcherPort
{
    /** @var list<array{command_type: string, payload: array<string, mixed>, tenant_id: string}> */
    private array $dispatchedCommands = [];

    private string $defaultStatus = 'COMMITTED';

    private ?string $defaultReason = null;

    public function setDefaultOutcome(string $status, ?string $reason = null): void
    {
        $this->defaultStatus = $status;
        $this->defaultReason = $reason;
    }

    public function dispatch(string $commandType, array $payload, string $tenantId): array
    {
        $this->dispatchedCommands[] = [
            'command_type' => $commandType,
            'payload' => $payload,
            'tenant_id' => $tenantId,
        ];

        if (($payload['fail_dispatch'] ?? false) === true) {
            return [
                'status' => 'REJECTED',
                'reason' => 'Domain rejected synthetic command',
            ];
        }

        return [
            'status' => $this->defaultStatus,
            'reason' => $this->defaultReason,
        ];
    }

    /**
     * @return list<array{command_type: string, payload: array<string, mixed>, tenant_id: string}>
     */
    public function getDispatchedCommands(): array
    {
        return $this->dispatchedCommands;
    }

    public function clear(): void
    {
        $this->dispatchedCommands = [];
    }
}
