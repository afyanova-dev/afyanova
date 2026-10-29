<?php

declare(strict_types=1);

use App\Platform\Notifications\Application\Command\UpsertNotificationTemplateCommand;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Platform\Notifications\Domain\Model\NotificationStatus;
use App\Platform\Notifications\Domain\Model\SuppressionReason;
use App\Platform\Notifications\Infrastructure\Adapter\ReverbInAppBroadcaster;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/notifications.php';

beforeEach(function (): void {
    ReverbInAppBroadcaster::reset();
});

it('delivers in-app notification via Reverb broadcaster and manages read lifecycle', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $userId = (string) $fx['user']->id;

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    // 1. Create IN_APP template
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'clinical.task.assigned',
            name: 'Clinical Task Assigned',
            category: 'SYSTEM',
            channels: ['IN_APP'],
            content: [
                'in_app' => [
                    'subject' => 'Clinical Task Assigned',
                    'en' => 'You have been assigned to patient task #{task_id}.',
                    'sw' => 'Umepangiwa jukumu la mgonjwa #{task_id}.',
                ],
            ],
            clinicalSafe: true,
            consentRequired: false,
        ));
    });

    // 2. Dispatch in-app notification to existing user
    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $userId) {
        return $api->send(new SendNotificationDto(
            templateKey: 'clinical.task.assigned',
            recipientType: 'USER',
            recipientId: $userId,
            onBehalfOfDomain: 'Clinical',
            onBehalfOfEntity: 'Task',
            context: [
                'task_id' => 'TSK-9901',
                'action_url' => '/workspace/clinical/tasks/TSK-9901',
            ],
            channels: ['IN_APP'],
            priority: 'ROUTINE',
        ));
    });

    expect($dto)->not->toBeNull();

    // 3. Verify Reverb broadcaster received the event
    expect(ReverbInAppBroadcaster::$broadcasts)->toHaveCount(1);
    $broadcast = ReverbInAppBroadcaster::$broadcasts[0];
    expect($broadcast['tenantId'])->toBe($tenantId)
        ->and($broadcast['userId'])->toBe($userId)
        ->and($broadcast['notification']->title)->toBe('Clinical Task Assigned')
        ->and($broadcast['notification']->body)->toContain('TSK-9901')
        ->and($broadcast['notification']->actionUrl)->toBe('/workspace/clinical/tasks/TSK-9901')
        ->and($broadcast['notification']->readAt)->toBeNull();

    // 4. Query in-app notifications
    $list = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getInAppNotifications($userId));
    expect($list)->toHaveCount(1)
        ->and($list[0]->id)->toBe($broadcast['notification']->id)
        ->and($list[0]->readAt)->toBeNull();

    // 5. Mark as read
    $inAppId = $list[0]->id;
    TenantContextScope::run($tenantId, $facilityId, fn () => $api->markInAppRead($inAppId, $userId));

    // 6. Query again: readAt timestamp is now populated
    $listAfter = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getInAppNotifications($userId));
    expect($listAfter)->toHaveCount(1)
        ->and($listAfter[0]->id)->toBe($inAppId)
        ->and($listAfter[0]->readAt)->not->toBeNull();
});

it('suppresses in-app notification when user account does not exist', function (): void {
    $fx = notificationsFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $nonExistentUserId = (string) Str::uuid();

    $api = app(NotificationsApi::class);
    $commandBus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus): void {
        $commandBus->dispatch(new UpsertNotificationTemplateCommand(
            key: 'system.broadcast',
            name: 'System Broadcast',
            category: 'SYSTEM',
            channels: ['IN_APP'],
            content: [
                'in_app' => ['en' => 'Maintenance alert'],
            ],
            clinicalSafe: true,
            consentRequired: false,
        ));
    });

    $dto = TenantContextScope::run($tenantId, $facilityId, function () use ($api, $nonExistentUserId) {
        return $api->send(new SendNotificationDto(
            templateKey: 'system.broadcast',
            recipientType: 'USER',
            recipientId: $nonExistentUserId,
            onBehalfOfDomain: 'System',
            onBehalfOfEntity: 'Maintenance',
            context: [],
            channels: ['IN_APP'],
        ));
    });

    expect($dto)->not->toBeNull();

    $status = TenantContextScope::run($tenantId, $facilityId, fn () => $api->getStatus($dto->id));
    expect($status->status)->toBe(NotificationStatus::SUPPRESSED->value)
        ->and($status->suppressionReason)->toBe(SuppressionReason::NO_USER_ACCOUNT->value);

    expect(ReverbInAppBroadcaster::$broadcasts)->toBeEmpty();
});

it('verifies zero cross-prefix foreign key constraints on notif_in_app_notifications (F7, ADR-093)', function (): void {
    $foreignKeys = DB::select("
        SELECT
            kcu.column_name,
            ccu.table_name AS foreign_table_name,
            ccu.column_name AS foreign_column_name
        FROM information_schema.table_constraints AS tc
        JOIN information_schema.key_column_usage AS kcu
          ON tc.constraint_name = kcu.constraint_name
          AND tc.table_schema = kcu.table_schema
        JOIN information_schema.constraint_column_usage AS ccu
          ON ccu.constraint_name = tc.constraint_name
          AND ccu.table_schema = tc.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY'
          AND tc.table_name = 'notif_in_app_notifications'
    ");

    // Strictly ZERO cross-prefix foreign keys to `users` or any non-`notif_` table
    foreach ($foreignKeys as $fk) {
        expect($fk->foreign_table_name)->not->toBe('users')
            ->and(str_starts_with((string) $fk->foreign_table_name, 'notif_'))->toBeTrue();
    }

    // In fact, notif_in_app_notifications has no cross-prefix FKs at all
    expect($foreignKeys)->toBeArray();
});
