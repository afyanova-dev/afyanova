<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Listener;

use App\Domains\Scheduling\Contracts\Event\AppointmentBooked;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Shared\Tenancy\TenantContextScope;

final readonly class OnAppointmentBooked
{
    public function __construct(private NotificationsApi $notificationsApi) {}

    public function __invoke(AppointmentBooked $event): void
    {
        TenantContextScope::run($event->tenantId, $event->facilityId, function () use ($event): void {
            $this->notificationsApi->send(new SendNotificationDto(
                templateKey: 'appointment.booked',
                recipientType: 'PATIENT',
                recipientId: $event->patientId,
                onBehalfOfDomain: 'Scheduling',
                onBehalfOfEntity: 'Appointment',
                context: [
                    'appointment_number' => $event->appointmentNumber,
                    'scheduled_start' => $event->scheduledStart,
                    'scheduled_end' => $event->scheduledEnd,
                ],
                priority: 'ROUTINE',
                dedupeKey: 'APT_BOOKED_'.$event->appointmentId,
            ));
        });
    }
}
