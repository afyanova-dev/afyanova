<?php

declare(strict_types=1);

use App\Platform\Notifications\Application\Command\UpsertNotificationTemplateCommand;
use App\Platform\Notifications\Contracts\DTO\SendBulkNotificationDto;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Platform\Notifications\Domain\Model\NotificationChannel;
use App\Platform\Notifications\Domain\Model\NotificationStatus;
use App\Platform\Notifications\Domain\Model\SuppressionReason;
use App\Platform\Notifications\Infrastructure\Adapter\SyncSmsGateway;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/notifications.php';

beforeEach(function (): void {
    SyncSmsGateway::reset();
});

it('manages complete notification lifecycle: accept -> render -> dispatch -> deliver', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    // 1. Create a notification template
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'appointment.booked',
            name: 'Appointment Confirmation',
            category: 'TRANSACTIONAL',
            channels: ['SMS'],
            content: [
                'sms' => [
                    'sw' => 'Ndugu mteja, miadi yako namba {appointment_number} imethibitishwa.',
                    'en' => 'Dear customer, your appointment {appointment_number} has been confirmed.',
                ],
            ],
            clinicalSafe: true,
            consentRequired: false,
        ));
    });

    // 2. Create a mock patient in database for live resolution
    $patientId = (string) Str::uuid();
    DB::table('pat_patients')->insert([
        'id' => $patientId,
        'tenant_id' => $tenantId,
        'patient_number' => 'PAT-10001',
        'given_name' => 'Juma',
        'family_name' => 'Bakari',
        'display_name' => 'Juma Bakari',
        'sex' => 'MALE',
        'primary_phone' => '+255712345678',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Send notification
    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId): mixed {
        return $api->send(new SendNotificationDto(
            templateKey: 'appointment.booked',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Scheduling',
            onBehalfOfEntity: 'Appointment',
            context: [
                'appointment_number' => 'APT-000123',
            ],
            channels: ['SMS'],
            priority: 'ROUTINE',
        ));
    });

    expect($dto)->not->toBeNull()
        ->and($dto->templateKey)->toBe('appointment.booked')
        ->and($dto->recipientType)->toBe('PATIENT')
        ->and($dto->recipientId)->toBe($patientId);

    // 4. Verify SMS Gateway received the rendered message
    expect(SyncSmsGateway::$dispatched)->toHaveCount(1)
        ->and(SyncSmsGateway::$dispatched[0]['toPhone'])->toBe('+255712345678')
        ->and(SyncSmsGateway::$dispatched[0]['content'])->toContain('APT-000123');

    // 5. Query status
    $status = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getStatus($dto->id));
    expect($status)->not->toBeNull()
        ->and($status->status)->toBe(NotificationStatus::DELIVERED->value)
        ->and($status->attempts)->toHaveCount(1)
        ->and($status->attempts[0]->channel)->toBe(NotificationChannel::SMS->value)
        ->and($status->attempts[0]->maskedDestination)->toBe('+255*****78');
});

it('cancels pending notification prior to dispatch', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'system.alert',
            name: 'System Alert',
            category: 'SYSTEM',
            channels: ['SMS'],
            content: ['sms' => ['sw' => 'Tahadhari ya mfumo: {msg}']],
        ));
    });

    $patientId = (string) Str::uuid();

    Queue::fake();

    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId): mixed {
        return $api->send(new SendNotificationDto(
            templateKey: 'system.alert',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'System',
            onBehalfOfEntity: 'Server',
            context: ['msg' => 'Matengenezo'],
        ));
    });

    TenantContextScope::run($tenantId, $facilityId, fn () => $api->cancelPending($dto->id));

    $status = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getStatus($dto->id));
    expect($status)->not->toBeNull()
        ->and($status->status)->toBe(NotificationStatus::SUPPRESSED->value)
        ->and($status->suppressionReason)->toBe(SuppressionReason::OPT_OUT->value);
});

it('dispatches bulk notifications across multiple recipients', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'clinic.campaign',
            name: 'Clinic Campaign',
            category: 'TRANSACTIONAL',
            channels: ['SMS'],
            content: ['sms' => ['sw' => 'Karibu kliniki kesho kwa huduma ya {service}.']],
        ));
    });

    $p1 = (string) Str::uuid();
    $p2 = (string) Str::uuid();

    foreach ([$p1 => '+255711111111', $p2 => '+255722222222'] as $pid => $ph) {
        DB::table('pat_patients')->insert([
            'id' => $pid,
            'tenant_id' => $tenantId,
            'patient_number' => 'PAT-'.rand(1000, 9999),
            'given_name' => 'Patient',
            'family_name' => 'Test',
            'display_name' => 'Patient Test',
            'sex' => 'FEMALE',
            'primary_phone' => $ph,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $bulkResponse = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $p1, $p2): mixed {
        return $api->sendBulk(new SendBulkNotificationDto(
            templateKey: 'clinic.campaign',
            recipients: [
                ['type' => 'PATIENT', 'id' => $p1],
                ['type' => 'PATIENT', 'id' => $p2],
            ],
            onBehalfOfDomain: 'PublicHealth',
            onBehalfOfEntity: 'Campaign',
            context: ['service' => 'Chanjo'],
        ));
    });

    expect($bulkResponse->queuedCount)->toBe(2)
        ->and($bulkResponse->requestIds)->toHaveCount(2)
        ->and(SyncSmsGateway::$dispatched)->toHaveCount(2);
});
