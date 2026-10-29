<?php

declare(strict_types=1);

use App\Platform\Notifications\Application\Command\SetChannelPreferenceCommand;
use App\Platform\Notifications\Application\Command\UpsertNotificationTemplateCommand;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Platform\Notifications\Domain\Model\NotificationChannel;
use App\Platform\Notifications\Domain\Model\NotificationStatus;
use App\Platform\Notifications\Domain\Model\SuppressionReason;
use App\Platform\Notifications\Infrastructure\Adapter\SyncSmsGateway;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/notifications.php';

beforeEach(function (): void {
    SyncSmsGateway::reset();
});

it('suppresses notification when consent basis is missing on consent_required template', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    // Create outreach template requiring consent
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'outreach.screening',
            name: 'Screening Outreach Campaign',
            category: 'OUTREACH',
            channels: ['SMS'],
            content: ['sms' => ['sw' => 'Tunakualika kupima afya bure.']],
            clinicalSafe: false,
            consentRequired: true,
        ));
    });

    $patientId = (string) Str::uuid();
    DB::table('pat_patients')->insert([
        'id' => $patientId,
        'tenant_id' => $tenantId,
        'patient_number' => 'PAT-20001',
        'given_name' => 'Asha',
        'family_name' => 'Khamis',
        'display_name' => 'Asha Khamis',
        'sex' => 'FEMALE',
        'primary_phone' => '+255733333333',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Send without consent basis ref -> MUST be suppressed with NO_CONSENT
    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId): mixed {
        return $api->send(new SendNotificationDto(
            templateKey: 'outreach.screening',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'PublicHealth',
            onBehalfOfEntity: 'Campaign',
            channels: ['SMS'],
            consentBasisRef: null,
        ));
    });

    $status = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getStatus($dto->id));
    expect($status)->not->toBeNull()
        ->and($status->status)->toBe(NotificationStatus::SUPPRESSED->value)
        ->and($status->suppressionReason)->toBe(SuppressionReason::NO_CONSENT->value)
        ->and(SyncSmsGateway::$dispatched)->toBeEmpty();
});

it('suppresses routine notifications during quiet hours but allows emergency alerts', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'reminder.general',
            name: 'General Reminder',
            category: 'REMINDER',
            channels: ['SMS'],
            content: ['sms' => ['sw' => 'Kumbusho: Miadi yako ni kesho.']],
        ));
    });

    $patientId = (string) Str::uuid();
    DB::table('pat_patients')->insert([
        'id' => $patientId,
        'tenant_id' => $tenantId,
        'patient_number' => 'PAT-20002',
        'given_name' => 'Baraka',
        'family_name' => 'Ali',
        'display_name' => 'Baraka Ali',
        'sex' => 'MALE',
        'primary_phone' => '+255744444444',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Set quiet hours spanning 00:00 to 23:59 (always active)
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $patientId): void {
        $commandBus->dispatch(new SetChannelPreferenceCommand(
            subjectType: 'PATIENT',
            subjectId: $patientId,
            preferredChannel: 'SMS',
            quietHoursStart: '00:00',
            quietHoursEnd: '23:59',
        ));
    });

    // 1. ROUTINE notification should be suppressed
    $routineDto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId): mixed {
        return $api->send(new SendNotificationDto(
            templateKey: 'reminder.general',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Scheduling',
            onBehalfOfEntity: 'Appointment',
            priority: 'ROUTINE',
        ));
    });

    $routineStatus = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getStatus($routineDto->id));
    expect($routineStatus->status)->toBe(NotificationStatus::SUPPRESSED->value)
        ->and($routineStatus->suppressionReason)->toBe(SuppressionReason::QUIET_HOURS->value)
        ->and(SyncSmsGateway::$dispatched)->toBeEmpty();

    // 2. EMERGENCY notification should bypass quiet hours
    $emergencyDto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId): mixed {
        return $api->send(new SendNotificationDto(
            templateKey: 'reminder.general',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Emergency',
            onBehalfOfEntity: 'Triage',
            priority: 'EMERGENCY',
        ));
    });

    $emergencyStatus = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getStatus($emergencyDto->id));
    expect($emergencyStatus->status)->toBe(NotificationStatus::DELIVERED->value)
        ->and(SyncSmsGateway::$dispatched)->toHaveCount(1);
});

it('suppresses in-app channel when recipient has no user account while SMS succeeds', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'prescription.ready',
            name: 'Prescription Ready',
            category: 'TRANSACTIONAL',
            channels: ['SMS', 'IN_APP'],
            content: [
                'sms' => ['sw' => 'Dawa zako zipo tayari.'],
                'in_app' => ['sw' => 'Dawa zako zipo tayari.'],
            ],
        ));
    });

    $patientId = (string) Str::uuid();
    DB::table('pat_patients')->insert([
        'id' => $patientId,
        'tenant_id' => $tenantId,
        'patient_number' => 'PAT-20003',
        'given_name' => 'Fatma',
        'family_name' => 'Salum',
        'display_name' => 'Fatma Salum',
        'sex' => 'FEMALE',
        'primary_phone' => '+255755555555',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Patient has no User account -> IN_APP attempt suppressed (NO_USER_ACCOUNT), SMS delivered
    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId): mixed {
        return $api->send(new SendNotificationDto(
            templateKey: 'prescription.ready',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Pharmacy',
            onBehalfOfEntity: 'Dispensing',
            channels: ['SMS', 'IN_APP'],
        ));
    });

    $status = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getStatus($dto->id));
    expect($status->status)->toBe(NotificationStatus::DELIVERED->value)
        ->and($status->attempts)->toHaveCount(2);

    $smsAttempt = collect($status->attempts)->firstWhere('channel', NotificationChannel::SMS->value);
    $inAppAttempt = collect($status->attempts)->firstWhere('channel', NotificationChannel::IN_APP->value);

    expect($smsAttempt->status)->toBe(NotificationStatus::DELIVERED->value)
        ->and($inAppAttempt->status)->toBe(NotificationStatus::SUPPRESSED->value)
        ->and($inAppAttempt->errorCode)->toBe('NO_USER_ACCOUNT')
        ->and(SyncSmsGateway::$dispatched)->toHaveCount(1);
});
