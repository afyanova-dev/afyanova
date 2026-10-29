<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Listener;

use App\Domains\Laboratory\Contracts\Event\LabResultVerified;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Shared\Tenancy\TenantContextScope;

final readonly class OnLabResultVerified
{
    public function __construct(private NotificationsApi $notificationsApi) {}

    public function __invoke(LabResultVerified $event): void
    {
        TenantContextScope::run($event->tenantId, $event->facilityId, function () use ($event): void {
            $this->notificationsApi->send(new SendNotificationDto(
                templateKey: 'lab.result_ready',
                recipientType: 'PATIENT',
                recipientId: $event->patientId,
                onBehalfOfDomain: 'Laboratory',
                onBehalfOfEntity: 'LabResult',
                context: [
                    'order_id' => $event->orderId,
                    'test_code' => $event->testCode,
                ],
                priority: 'ROUTINE',
                dedupeKey: 'LAB_VERIFIED_'.$event->resultId,
            ));
        });
    }
}
