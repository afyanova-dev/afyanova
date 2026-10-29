<?php

declare(strict_types=1);

use App\Platform\Notifications\Application\Command\UpsertNotificationTemplateCommand;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Platform\Notifications\Domain\Exception\DuplicateNotificationException;
use App\Platform\Notifications\Domain\Model\DeduplicationKey;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/notifications.php';

it('enforces atomic deduplication and rejects duplicate submissions within window', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'payment.receipt',
            name: 'Payment Receipt',
            category: 'TRANSACTIONAL',
            channels: ['SMS'],
            content: ['sms' => ['sw' => 'Malipo ya risiti {receipt_no} yamepokelewa.']],
        ));
    });

    $patientId = (string) Str::uuid();
    DB::table('pat_patients')->insert([
        'id' => $patientId,
        'tenant_id' => $tenantId,
        'patient_number' => 'PAT-30001',
        'given_name' => 'Neema',
        'family_name' => 'Mushi',
        'display_name' => 'Neema Mushi',
        'sex' => 'FEMALE',
        'primary_phone' => '+255766666666',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $dedupeKey = 'RCP_DEDUPE_999';

    // 1. First send with dedupe_key succeeds
    $first = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId, $dedupeKey): mixed {
        return $api->send(new SendNotificationDto(
            templateKey: 'payment.receipt',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Billing',
            onBehalfOfEntity: 'Receipt',
            context: ['receipt_no' => 'RCP-999'],
            dedupeKey: $dedupeKey,
        ));
    });

    expect($first)->not->toBeNull();

    // 2. Immediate second send with same dedupe_key must throw DuplicateNotificationException
    expect(function () use ($api, $patientId, $dedupeKey, $tenantId, $facilityId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId, $dedupeKey): void {
            $api->send(new SendNotificationDto(
                templateKey: 'payment.receipt',
                recipientType: 'PATIENT',
                recipientId: $patientId,
                onBehalfOfDomain: 'Billing',
                onBehalfOfEntity: 'Receipt',
                context: ['receipt_no' => 'RCP-999'],
                dedupeKey: $dedupeKey,
            ));
        });
    })->toThrow(DuplicateNotificationException::class);

    // 3. Fast-forward expiration in database
    TenantContextScope::run($tenantId, $facilityId, function () use ($dedupeKey): void {
        DeduplicationKey::query()
            ->where('dedupe_key', $dedupeKey)
            ->update(['expires_at' => CarbonImmutable::now()->subMinute()]);
    });

    // 4. Send after expiration succeeds
    $third = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId, $dedupeKey): mixed {
        return $api->send(new SendNotificationDto(
            templateKey: 'payment.receipt',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Billing',
            onBehalfOfEntity: 'Receipt',
            context: ['receipt_no' => 'RCP-999'],
            dedupeKey: $dedupeKey,
        ));
    });

    expect($third)->not->toBeNull()
        ->and($third->id)->not->toBe($first->id);
});
