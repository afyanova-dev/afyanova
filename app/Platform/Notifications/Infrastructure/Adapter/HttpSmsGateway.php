<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Adapter;

use App\Platform\Notifications\Domain\Port\GatewayResultDto;
use App\Platform\Notifications\Domain\Port\SmsGatewayPort;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class HttpSmsGateway implements SmsGatewayPort
{
    public function __construct(
        private readonly ?string $endpoint = null,
        private readonly ?string $apiKey = null,
        private readonly ?string $defaultSenderId = null,
        private readonly ?int $timeout = null,
        private readonly ?string $defaultCountryCode = null,
    ) {}

    public function sendSms(string $toPhone, string $content, ?string $senderId = null): GatewayResultDto
    {
        $normalizedPhone = $this->normalizePhone($toPhone);
        $endpoint = $this->endpoint ?? (string) config('notifications.sms.endpoint', '');
        if ($endpoint === '') {
            return GatewayResultDto::fail(
                errorCode: 'GATEWAY_NOT_CONFIGURED',
                errorMessage: 'SMS gateway endpoint URL is not configured.',
            );
        }
        $apiKey = $this->apiKey ?? (string) config('notifications.sms.api_key', '');
        $sender = $senderId ?? $this->defaultSenderId ?? (string) config('notifications.sms.sender_id', 'AFYANOVA');
        $timeout = $this->timeout ?? (int) config('notifications.sms.timeout', 15);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post($endpoint, [
                    'to' => $normalizedPhone,
                    'from' => $sender,
                    'message' => $content,
                ]);

            if ($response->successful()) {
                /** @var array{id?: string, message_id?: string, status?: string}|null $data */
                $data = $response->json();
                $providerId = (is_array($data) ? ($data['message_id'] ?? $data['id'] ?? null) : null) ?? 'SMS-HTTP-'.bin2hex(random_bytes(6));

                Log::info("HttpSmsGateway: Message dispatched to [{$normalizedPhone}] via [{$endpoint}] with ID [{$providerId}]");

                return GatewayResultDto::ok($providerId);
            }

            $statusCode = (string) $response->status();
            $body = $response->body();
            Log::warning("HttpSmsGateway: Failed sending SMS to [{$normalizedPhone}], status [{$statusCode}], body: {$body}");

            return GatewayResultDto::fail(
                errorCode: "HTTP_{$statusCode}",
                errorMessage: "SMS gateway responded with HTTP status {$statusCode}: {$body}",
            );
        } catch (Throwable $e) {
            Log::error("HttpSmsGateway: Exception while sending SMS to [{$normalizedPhone}]: {$e->getMessage()}");

            return GatewayResultDto::fail(
                errorCode: 'GATEWAY_CONNECTION_ERROR',
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Normalize phone number to E.164 standard.
     */
    public function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';

        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        $defaultCountry = $this->defaultCountryCode ?? (string) config('notifications.sms.default_country_code', '+254');
        $countryCode = ltrim($defaultCountry, '+');

        // Handles local prefix like 0712345678 or 0112345678
        if (str_starts_with($cleaned, '0')) {
            return '+'.$countryCode.substr($cleaned, 1);
        }

        if (str_starts_with($cleaned, $countryCode)) {
            return '+'.$cleaned;
        }

        return '+'.$countryCode.$cleaned;
    }
}
