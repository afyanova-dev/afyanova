<?php

declare(strict_types=1);

use App\Platform\Notifications\Application\Command\SetChannelPreferenceCommand;
use App\Platform\Notifications\Application\Command\UpsertNotificationTemplateCommand;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Platform\Notifications\Domain\Model\ChannelPreference;
use App\Platform\Notifications\Domain\Model\DeliveryAttempt;
use App\Platform\Notifications\Domain\Model\InAppNotification;
use App\Platform\Notifications\Domain\Model\NotificationRequest;
use App\Platform\Notifications\Domain\Model\NotificationTemplate;
use App\Platform\Notifications\Domain\Repository\DeduplicationRepositoryInterface;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/notifications.php';

it('enforces row level security across tenants for templates, requests, attempts, preferences, and in-app notifications', function (): void {
    $fxA = notificationsFixture();
    $tenantA = (string) $fxA['tenant']->id;
    $facilityA = (string) $fxA['facility']->id;
    $userAId = (string) $fxA['user']->id;

    $fxB = notificationsFixture();
    $tenantB = (string) $fxB['tenant']->id;
    $facilityB = (string) $fxB['facility']->id;
    $userBId = (string) $fxB['user']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);
    $dedupeRepo = app(DeduplicationRepositoryInterface::class);

    $patientAId = (string) Str::uuid();

    // ── 1. Tenant A populates notification records ─────────────────────
    $createdA = TenantContextScope::run($tenantA, $facilityA, function () use ($commandBus, $api, $patientAId, $tenantA, $userAId): array {
        // Template in Tenant A
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'tenant.rls.alert',
            name: 'Tenant A Alert',
            category: 'SYSTEM',
            channels: ['SMS', 'IN_APP'],
            content: [
                'sms' => ['en' => 'Tenant A SMS.'],
                'in_app' => ['en' => 'Tenant A InApp.'],
            ],
            clinicalSafe: true,
            consentRequired: false,
        ));

        // Patient in Tenant A
        DB::table('pat_patients')->insert([
            'id' => $patientAId,
            'tenant_id' => $tenantA,
            'patient_number' => 'PAT-RLS-01',
            'given_name' => 'Fatma',
            'family_name' => 'Ali',
            'display_name' => 'Fatma Ali',
            'sex' => 'FEMALE',
            'primary_phone' => '+255713998877',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Preference in Tenant A
        $commandBus->dispatch(new SetChannelPreferenceCommand(
            subjectType: 'PATIENT',
            subjectId: $patientAId,
            preferredChannel: 'SMS',
            mutedCategories: [],
            quietHoursStart: null,
            quietHoursEnd: null,
            preferredLanguage: 'sw',
        ));

        // Notification Request in Tenant A
        $dto = $api->send(new SendNotificationDto(
            templateKey: 'tenant.rls.alert',
            recipientType: 'PATIENT',
            recipientId: $patientAId,
            onBehalfOfDomain: 'Billing',
            onBehalfOfEntity: 'Invoice',
            context: [],
            channels: ['SMS'],
            dedupeKey: 'dedupe-rls-100',
        ));

        // In-App Notification in Tenant A
        $inApp = InAppNotification::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantA,
            'user_id' => $userAId,
            'notification_request_id' => $dto->id,
            'title' => 'Tenant A Notice',
            'body' => 'Private tenant A notice',
            'action_url' => null,
            'read_at' => null,
        ]);

        $template = NotificationTemplate::query()->where('key', 'tenant.rls.alert')->first();

        return [
            'templateId' => $template?->id,
            'requestId' => $dto->id,
            'inAppId' => $inApp->id,
        ];
    });

    expect($createdA['templateId'])->not->toBeNull()
        ->and($createdA['requestId'])->not->toBeNull();

    // ── 2. Tenant B verifies complete isolation under PostgreSQL RLS ──
    TenantContextScope::run($tenantB, $facilityB, function () use ($createdA, $patientAId, $dedupeRepo, $commandBus): void {
        // Template A is invisible to Tenant B
        $templateInB = NotificationTemplate::query()->find($createdA['templateId']);
        expect($templateInB)->toBeNull();

        // Notification Request A is invisible to Tenant B
        $requestInB = NotificationRequest::query()->find($createdA['requestId']);
        expect($requestInB)->toBeNull();

        // Delivery Attempts for Request A are invisible to Tenant B
        $attemptsInB = DeliveryAttempt::query()->where('notification_request_id', $createdA['requestId'])->get();
        expect($attemptsInB)->toBeEmpty();

        // Channel Preference for Patient A is invisible to Tenant B
        $prefInB = ChannelPreference::query()->where('subject_id', $patientAId)->first();
        expect($prefInB)->toBeNull();

        // In-App Notification A is invisible to Tenant B
        $inAppInB = InAppNotification::query()->find($createdA['inAppId']);
        expect($inAppInB)->toBeNull();

        // Tenant B can create its own template with the SAME key without collision
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'tenant.rls.alert',
            name: 'Tenant B Alert',
            category: 'SYSTEM',
            channels: ['SMS'],
            content: [
                'sms' => ['en' => 'Tenant B SMS.'],
            ],
            clinicalSafe: true,
            consentRequired: false,
        ));

        $templateB = NotificationTemplate::query()->where('key', 'tenant.rls.alert')->first();
        expect($templateB)->not->toBeNull()
            ->and($templateB->name)->toBe('Tenant B Alert')
            ->and($templateB->id)->not->toBe($createdA['templateId']);

        // Tenant B can acquire the exact SAME deduplication key without conflict
        $acquired = $dedupeRepo->acquireLock(
            'dedupe-rls-100',
            (string) Str::uuid(),
        );
        expect($acquired)->toBeTrue();
    });
});
