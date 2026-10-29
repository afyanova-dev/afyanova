<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Listener;

use App\Domains\Pharmacy\Contracts\Event\MedicationDispensed;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Shared\Tenancy\TenantContextScope;

final readonly class OnMedicationDispensed
{
    public function __construct(private NotificationsApi $notificationsApi) {}

    public function __invoke(MedicationDispensed $event): void
    {
        TenantContextScope::run($event->tenantId, $event->facilityId, function () use ($event): void {
            $this->notificationsApi->send(new SendNotificationDto(
                templateKey: 'pharmacy.dispensed',
                recipientType: 'PATIENT',
                recipientId: $event->patientId,
                onBehalfOfDomain: 'Pharmacy',
                onBehalfOfEntity: 'Dispensing',
                context: [
                    'dispensing_id' => $event->dispensingId,
                    'quantity' => $event->quantity,
                ],
                priority: 'ROUTINE',
                dedupeKey: 'MED_DISPENSED_'.$event->dispensingId,
            ));
        });
    }
}
