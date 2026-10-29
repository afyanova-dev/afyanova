<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Fixture;

use App\Platform\Integration\Contracts\Port\IntegrationTransportClient;
use App\Platform\Integration\Domain\Model\OutboundMessage;

final class SyntheticTransportClient implements IntegrationTransportClient
{
    /** @var list<array{message: OutboundMessage, target_url: string, headers: array<string, string>}> */
    private array $sentCalls = [];

    private bool $nextShouldFail = false;

    private string $failureReason = 'Synthetic transport connection timeout';

    private ?string $nextProviderRef = 'SYNTH-OUT-999';

    public function setNextShouldFail(bool $fail, string $reason = 'Synthetic transport connection timeout'): void
    {
        $this->nextShouldFail = $fail;
        $this->failureReason = $reason;
    }

    public function setNextProviderRef(?string $ref): void
    {
        $this->nextProviderRef = $ref;
    }

    public function send(OutboundMessage $message, string $targetUrl, array $headers = []): array
    {
        $this->sentCalls[] = [
            'message' => $message,
            'target_url' => $targetUrl,
            'headers' => $headers,
        ];

        if ($this->nextShouldFail) {
            return [
                'success' => false,
                'provider_ref' => null,
                'response' => ['error' => $this->failureReason],
                'error' => $this->failureReason,
            ];
        }

        return [
            'success' => true,
            'provider_ref' => $this->nextProviderRef,
            'response' => ['status' => 'ACCEPTED', 'provider_ref' => $this->nextProviderRef],
            'error' => null,
        ];
    }

    /**
     * @return list<array{message: OutboundMessage, target_url: string, headers: array<string, string>}>
     */
    public function getSentCalls(): array
    {
        return $this->sentCalls;
    }

    public function clear(): void
    {
        $this->sentCalls = [];
    }
}
