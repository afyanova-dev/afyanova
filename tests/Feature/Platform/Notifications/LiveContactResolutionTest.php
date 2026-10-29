<?php

declare(strict_types=1);

use App\Platform\Notifications\Application\Command\UpsertNotificationTemplateCommand;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Platform\Notifications\Domain\Model\NotificationChannel;
use App\Platform\Notifications\Domain\Model\NotificationStatus;
use App\Platform\Notifications\Domain\Model\SuppressionReason;
use App\Platform\Notifications\Infrastructure\Adapter\SyncEmailGateway;
use App\Platform\Notifications\Infrastructure\Adapter\SyncSmsGateway;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/notifications.php';

beforeEach(function (): void {
    SyncSmsGateway::reset();
    SyncEmailGateway::reset();
});

it('resolves patient contact live at dispatch time and masks destination in persistence (F4)', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    // 1. Create template supporting SMS and EMAIL
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'patient.reminder',
            name: 'Patient Reminder',
            category: 'TRANSACTIONAL',
            channels: ['SMS', 'EMAIL'],
            content: [
                'sms' => ['en' => 'Reminder for {name}.'],
                'email' => ['en' => 'Dear {name}, here is your reminder.'],
            ],
            clinicalSafe: true,
            consentRequired: false,
        ));
    });

    // 2. Create patient in pat_patients
    $patientId = (string) Str::uuid();
    DB::table('pat_patients')->insert([
        'id' => $patientId,
        'tenant_id' => $tenantId,
        'patient_number' => 'PAT-20001',
        'given_name' => 'Asha',
        'family_name' => 'Juma',
        'display_name' => 'Asha Juma',
        'sex' => 'FEMALE',
        'primary_phone' => '+255711223344',
        'email' => 'asha.juma@example.com',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Send notification
    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId) {
        return $api->send(new SendNotificationDto(
            templateKey: 'patient.reminder',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Clinical',
            onBehalfOfEntity: 'Encounter',
            context: ['name' => 'Asha'],
            channels: ['SMS', 'EMAIL'],
        ));
    });

    expect($dto)->not->toBeNull();

    // 4. Verify gateways dispatched to real unmasked contacts
    expect(SyncSmsGateway::$dispatched)->toHaveCount(1)
        ->and(SyncSmsGateway::$dispatched[0]['toPhone'])->toBe('+255711223344');

    expect(SyncEmailGateway::$dispatched)->toHaveCount(1)
        ->and(SyncEmailGateway::$dispatched[0]['toEmail'])->toBe('asha.juma@example.com');

    // 5. Verify database records: strictly ZERO unmasked contacts stored (F4 closure)
    $dbRequest = DB::table('notif_requests')->where('id', $dto->id)->first();
    expect($dbRequest)->not->toBeNull()
        ->and(json_encode($dbRequest))->not->toContain('+255711223344')
        ->and(json_encode($dbRequest))->not->toContain('asha.juma@example.com');

    $dbAttempts = DB::table('notif_delivery_attempts')->where('notification_request_id', $dto->id)->get();
    expect($dbAttempts)->toHaveCount(2);

    foreach ($dbAttempts as $attempt) {
        expect($attempt->masked_destination)->not->toBeNull()
            ->and($attempt->masked_destination)->not->toBe('+255711223344')
            ->and($attempt->masked_destination)->not->toBe('asha.juma@example.com');

        if ($attempt->channel === NotificationChannel::SMS->value) {
            expect($attempt->masked_destination)->toBe('+255*****44');
        } elseif ($attempt->channel === NotificationChannel::EMAIL->value) {
            expect($attempt->masked_destination)->toBe('a***@example.com');
        }
    }

    // 6. Live update: update patient contact in Patient domain
    DB::table('pat_patients')->where('id', $patientId)->update([
        'primary_phone' => '+255799887766',
        'email' => 'asha.new@example.com',
    ]);

    // Send a second notification
    $dto2 = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId) {
        return $api->send(new SendNotificationDto(
            templateKey: 'patient.reminder',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Clinical',
            onBehalfOfEntity: 'Encounter',
            context: ['name' => 'Asha'],
            channels: ['SMS'],
        ));
    });

    expect($dto2)->not->toBeNull();

    // Verify SMS Gateway immediately picked up the updated phone number without stale caching
    expect(SyncSmsGateway::$dispatched)->toHaveCount(2)
        ->and(SyncSmsGateway::$dispatched[1]['toPhone'])->toBe('+255799887766');
});

it('suppresses notification if patient is deceased or consent is missing', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'patient.followup',
            name: 'Patient Followup',
            category: 'OUTREACH',
            channels: ['SMS'],
            content: [
                'sms' => ['en' => 'Followup alert'],
            ],
            clinicalSafe: false,
            consentRequired: true,
        ));
    });

    $patientId = (string) Str::uuid();
    DB::table('pat_patients')->insert([
        'id' => $patientId,
        'tenant_id' => $tenantId,
        'patient_number' => 'PAT-20002',
        'given_name' => 'Said',
        'family_name' => 'Ali',
        'display_name' => 'Said Ali',
        'sex' => 'MALE',
        'primary_phone' => '+255711000000',
        'status' => 'DECEASED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId) {
        return $api->send(new SendNotificationDto(
            templateKey: 'patient.followup',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'CareCoordination',
            onBehalfOfEntity: 'Episode',
            context: [],
            channels: ['SMS'],
        ));
    });

    expect($dto)->not->toBeNull();

    $status = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getStatus($dto->id));
    expect($status->status)->toBe(NotificationStatus::SUPPRESSED->value)
        ->and($status->suppressionReason)->toBe(SuppressionReason::NO_CONSENT->value);

    expect(SyncSmsGateway::$dispatched)->toBeEmpty();
});

it('resolves staff contact live at dispatch time via HRApi (F4)', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'staff.duty.alert',
            name: 'Staff Duty Alert',
            category: 'SYSTEM',
            channels: ['SMS', 'EMAIL'],
            content: [
                'sms' => ['en' => 'Shift starts at {time}.'],
                'email' => ['en' => 'Dear staff, shift starts at {time}.'],
            ],
            clinicalSafe: true,
            consentRequired: false,
        ));
    });

    $personId = (string) Str::uuid();
    DB::table('hr_persons')->insert([
        'id' => $personId,
        'tenant_id' => $tenantId,
        'first_name' => 'Amina',
        'last_name' => 'Kassim',
        'date_of_birth' => '1990-01-01',
        'gender' => 'FEMALE',
        'phone' => '+255788112233',
        'email' => 'dr.amina@hospital.or.tz',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $staffId = (string) Str::uuid();
    DB::table('hr_staff')->insert([
        'id' => $staffId,
        'tenant_id' => $tenantId,
        'person_id' => $personId,
        'staff_number' => 'STF-00000001',
        'status' => 'ACTIVE',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $staffId) {
        return $api->send(new SendNotificationDto(
            templateKey: 'staff.duty.alert',
            recipientType: 'STAFF',
            recipientId: $staffId,
            onBehalfOfDomain: 'HR',
            onBehalfOfEntity: 'Roster',
            context: ['time' => '08:00 AM'],
            channels: ['SMS', 'EMAIL'],
        ));
    });

    expect($dto)->not->toBeNull();

    expect(SyncSmsGateway::$dispatched)->toHaveCount(1)
        ->and(SyncSmsGateway::$dispatched[0]['toPhone'])->toBe('+255788112233');

    expect(SyncEmailGateway::$dispatched)->toHaveCount(1)
        ->and(SyncEmailGateway::$dispatched[0]['toEmail'])->toBe('dr.amina@hospital.or.tz');

    $status = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getStatus($dto->id));
    expect($status->status)->toBe(NotificationStatus::DELIVERED->value)
        ->and($status->attempts)->toHaveCount(2);

    $destinations = array_map(fn ($a) => $a->maskedDestination, $status->attempts);
    expect($destinations)->toContain('+255*****33')
        ->and($destinations)->toContain('d***@hospital.or.tz');
});
