<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Transport;

use App\Platform\Integration\Contracts\Port\IntegrationTransportClient;
use App\Platform\Integration\Domain\Model\OutboundMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class HttpTransportClient implements IntegrationTransportClient
{
    public function __construct(
        private readonly int $timeout = 30,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @return array{success: bool, provider_ref: ?string, response: ?array<string, mixed>, error: ?string}
     */
    public function send(OutboundMessage $message, string $targetUrl, array $headers = []): array
    {
        $payload = $message->payload;

        try {
            $response = Http::withHeaders(array_merge([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Correlation-ID' => $message->correlation_id,
            ], $headers))
                ->timeout($this->timeout)
                ->post($targetUrl, $payload);

            if ($response->successful()) {
                /** @var array<string, mixed>|null $data */
                $data = $response->json();
                $providerRef = (is_array($data) ? ($data['id'] ?? $data['ref'] ?? $data['reference'] ?? null) : null)
                    ?? 'HTTP-OUT-'.bin2hex(random_bytes(6));

                Log::info("HttpTransportClient: Dispatched OutboundMessage [{$message->id}] to [{$targetUrl}] with provider ref [{$providerRef}]");

                return [
                    'success' => true,
                    'provider_ref' => (string) $providerRef,
                    'response' => is_array($data) ? $data : ['body' => $response->body()],
                    'error' => null,
                ];
            }

            $statusCode = $response->status();
            $errorBody = $response->body();
            Log::warning("HttpTransportClient: Remote server rejected OutboundMessage [{$message->id}] with status [{$statusCode}]: {$errorBody}");

            return [
                'success' => false,
                'provider_ref' => null,
                'response' => $response->json(),
                'error' => "HTTP {$statusCode}: {$errorBody}",
            ];
        } catch (Throwable $e) {
            Log::error("HttpTransportClient: Transport exception for OutboundMessage [{$message->id}]: {$e->getMessage()}");

            return [
                'success' => false,
                'provider_ref' => null,
                'response' => null,
                'error' => "Transport connection failure: {$e->getMessage()}",
            ];
        }
    }
}
