<?php

declare(strict_types=1);

use App\Platform\Notifications\Application\Command\UpsertNotificationTemplateCommand;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Platform\Notifications\Infrastructure\Adapter\SyncSmsGateway;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/notifications.php';

beforeEach(function (): void {
    SyncSmsGateway::reset();
});

it('delivers OTP code via SMS gateway while redacting secrets from database storage (F3)', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    // 1. Create OTP template
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'auth.otp',
            name: 'Authentication OTP',
            category: 'SYSTEM',
            channels: ['SMS'],
            content: [
                'sms' => [
                    'sw' => 'Nambari yako ya uthibitisho ni {otp}. Usishiriki na mtu yeyote.',
                    'en' => 'Your verification code is {otp}. Do not share it with anyone.',
                ],
            ],
            clinicalSafe: true,
            consentRequired: false,
        ));
    });

    // 2. Create patient recipient
    $patientId = (string) Str::uuid();
    DB::table('pat_patients')->insert([
        'id' => $patientId,
        'tenant_id' => $tenantId,
        'patient_number' => 'PAT-30001',
        'given_name' => 'Baraka',
        'family_name' => 'Rashid',
        'display_name' => 'Baraka Rashid',
        'sex' => 'MALE',
        'primary_phone' => '+255784990011',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Send OTP notification with sensitive plaintext OTP token
    $secretOtp = '749210';
    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId, $secretOtp) {
        return $api->send(new SendNotificationDto(
            templateKey: 'auth.otp',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Authentication',
            onBehalfOfEntity: 'Session',
            context: [
                'otp' => $secretOtp,
                'expiry_minutes' => 5,
            ],
            channels: ['SMS'],
            priority: 'CRITICAL',
        ));
    });

    expect($dto)->not->toBeNull();

    // 4. In-memory delivery: Gateway receives the REAL rendered OTP so SMS delivery succeeds
    expect(SyncSmsGateway::$dispatched)->toHaveCount(1)
        ->and(SyncSmsGateway::$dispatched[0]['toPhone'])->toBe('+255784990011')
        ->and(SyncSmsGateway::$dispatched[0]['content'])->toContain('749210')
        ->and(SyncSmsGateway::$dispatched[0]['content'])->toContain('Usishiriki na mtu yeyote');

    // 5. Database persistence: strictly ZERO plaintext OTP stored in database (F3 closure)
    $dbRecord = DB::table('notif_requests')->where('id', $dto->id)->first();
    expect($dbRecord)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $dbRecord->context_payload, true);
    expect($payload['otp'])->toBe('[REDACTED]')
        ->and($payload['expiry_minutes'])->toBe(5);

    // Entire DB row as string MUST NOT contain the secret code
    $dbJson = json_encode($dbRecord);
    expect($dbJson)->not->toContain('749210')
        ->and($dbJson)->toContain('[REDACTED]');
});

it('redacts multiple sensitive keys in nested payloads (F3)', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'auth.credentials.changed',
            name: 'Credentials Changed',
            category: 'SYSTEM',
            channels: ['SMS'],
            content: [
                'sms' => ['en' => 'Your security settings have been updated.'],
            ],
            clinicalSafe: true,
            consentRequired: false,
        ));
    });

    $patientId = (string) Str::uuid();
    DB::table('pat_patients')->insert([
        'id' => $patientId,
        'tenant_id' => $tenantId,
        'patient_number' => 'PAT-30002',
        'given_name' => 'Subira',
        'family_name' => 'Mussa',
        'display_name' => 'Subira Mussa',
        'sex' => 'FEMALE',
        'primary_phone' => '+255784990022',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $patientId) {
        return $api->send(new SendNotificationDto(
            templateKey: 'auth.credentials.changed',
            recipientType: 'PATIENT',
            recipientId: $patientId,
            onBehalfOfDomain: 'Authentication',
            onBehalfOfEntity: 'User',
            context: [
                'password' => 'SuperSecret123!',
                'token' => 'jwt.token.value',
                'pin' => '4321',
                'auth_code' => 'CODE-ABC',
                'public_info' => 'regular_value',
                'security' => [
                    'secret' => 'nestedSecretKey',
                    'otp_code' => '999111',
                ],
            ],
            channels: ['SMS'],
        ));
    });

    expect($dto)->not->toBeNull();

    $dbRecord = DB::table('notif_requests')->where('id', $dto->id)->first();
    expect($dbRecord)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $dbRecord->context_payload, true);

    expect($payload['password'])->toBe('[REDACTED]')
        ->and($payload['token'])->toBe('[REDACTED]')
        ->and($payload['pin'])->toBe('[REDACTED]')
        ->and($payload['auth_code'])->toBe('[REDACTED]')
        ->and($payload['public_info'])->toBe('regular_value')
        ->and($payload['security']['secret'])->toBe('[REDACTED]')
        ->and($payload['security']['otp_code'])->toBe('[REDACTED]');

    $dbJson = json_encode($dbRecord);
    expect($dbJson)->not->toContain('SuperSecret123!')
        ->and($dbJson)->not->toContain('jwt.token.value')
        ->and($dbJson)->not->toContain('4321')
        ->and($dbJson)->not->toContain('CODE-ABC')
        ->and($dbJson)->not->toContain('nestedSecretKey')
        ->and($dbJson)->not->toContain('999111');
});
