<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Translator;

use App\Platform\Integration\Contracts\Port\InboundTranslatorPort;
use App\Platform\Integration\Domain\Enum\ConnectorFamily;
use App\Platform\Integration\Domain\Exception\TranslationFailedException;
use App\Platform\Integration\Domain\Model\InboundMessage;

final class MpesaInboundTranslator implements InboundTranslatorPort
{
    public function supports(string $connectorFamily, string $eventType): bool
    {
        if ($connectorFamily === ConnectorFamily::PAYMENT_GATEWAY->value) {
            return true;
        }

        return str_starts_with(strtoupper($eventType), 'MPESA')
            || in_array(strtoupper($eventType), ['C2B_PAYMENT', 'STK_CALLBACK', 'PAYMENT_CONFIRMATION'], true);
    }

    /**
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    public function translate(InboundMessage $message): array
    {
        $raw = $message->raw_payload ?? [];

        // 1. Check for Daraja STK Push Callback format (Body.stkCallback)
        if (isset($raw['Body']['stkCallback'])) {
            return $this->translateStkCallback($raw['Body']['stkCallback'], $message);
        }

        // 2. Check for Daraja C2B confirmation format (TransID, TransAmount, BillRefNumber, etc.)
        if (isset($raw['TransID'])) {
            return $this->translateC2bPayment($raw, $message);
        }

        throw new TranslationFailedException('Unrecognized M-Pesa payload structure: missing TransID or stkCallback');
    }

    /**
     * @param  array<string, mixed>  $stk
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    private function translateStkCallback(array $stk, InboundMessage $message): array
    {
        $resultCode = (int) ($stk['ResultCode'] ?? -1);
        $resultDesc = (string) ($stk['ResultDesc'] ?? 'No description');
        $merchantRequestId = (string) ($stk['MerchantRequestID'] ?? '');
        $checkoutRequestId = (string) ($stk['CheckoutRequestID'] ?? '');

        $metadataItems = $stk['CallbackMetadata']['Item'] ?? [];
        $meta = [];
        if (is_array($metadataItems)) {
            foreach ($metadataItems as $item) {
                if (isset($item['Name'], $item['Value'])) {
                    $meta[(string) $item['Name']] = $item['Value'];
                }
            }
        }

        $receiptNumber = (string) ($meta['MpesaReceiptNumber'] ?? $message->external_ref);
        $amount = (float) ($meta['Amount'] ?? 0.0);
        $phoneNumber = (string) ($meta['PhoneNumber'] ?? '');

        return [
            'command_type' => 'RecordExternalPayment',
            'payload' => [
                'provider' => 'MPESA',
                'channel' => 'STK_PUSH',
                'transaction_reference' => $receiptNumber,
                'amount' => $amount,
                'msisdn' => $phoneNumber,
                'bill_reference' => $message->correlation_id,
                'merchant_request_id' => $merchantRequestId,
                'checkout_request_id' => $checkoutRequestId,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'is_successful' => ($resultCode === 0),
                'external_ref' => $message->external_ref,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{command_type: string, payload: array<string, mixed>}
     */
    private function translateC2bPayment(array $raw, InboundMessage $message): array
    {
        $transId = (string) $raw['TransID'];
        $amount = (float) ($raw['TransAmount'] ?? 0.0);
        $billRef = (string) ($raw['BillRefNumber'] ?? '');
        $msisdn = (string) ($raw['MSISDN'] ?? '');
        $transTime = (string) ($raw['TransTime'] ?? '');

        if ($transId === '' || $amount <= 0.0) {
            throw new TranslationFailedException('M-Pesa C2B payment requires valid TransID and positive TransAmount');
        }

        return [
            'command_type' => 'RecordExternalPayment',
            'payload' => [
                'provider' => 'MPESA',
                'channel' => 'C2B',
                'transaction_reference' => $transId,
                'amount' => $amount,
                'msisdn' => $msisdn,
                'bill_reference' => $billRef,
                'transaction_time' => $transTime,
                'result_code' => 0,
                'result_desc' => 'Success',
                'is_successful' => true,
                'external_ref' => $message->external_ref,
            ],
        ];
    }
}
