<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Domains\Laboratory\Contracts\Event\LabResultVerified;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensed;
use App\Domains\Scheduling\Contracts\Event\AppointmentBooked;
use App\Platform\Notifications\Application\Command\CancelPendingNotificationCommand;
use App\Platform\Notifications\Application\Command\CancelPendingNotificationHandler;
use App\Platform\Notifications\Application\Command\MarkInAppReadCommand;
use App\Platform\Notifications\Application\Command\MarkInAppReadHandler;
use App\Platform\Notifications\Application\Command\SendBulkNotificationCommand;
use App\Platform\Notifications\Application\Command\SendBulkNotificationHandler;
use App\Platform\Notifications\Application\Command\SendNotificationCommand;
use App\Platform\Notifications\Application\Command\SendNotificationHandler;
use App\Platform\Notifications\Application\Command\SetChannelPreferenceCommand;
use App\Platform\Notifications\Application\Command\SetChannelPreferenceHandler;
use App\Platform\Notifications\Application\Command\UpsertNotificationTemplateCommand;
use App\Platform\Notifications\Application\Command\UpsertNotificationTemplateHandler;
use App\Platform\Notifications\Application\Query\GetChannelPreferenceHandler;
use App\Platform\Notifications\Application\Query\GetChannelPreferenceQuery;
use App\Platform\Notifications\Application\Query\GetInAppNotificationsHandler;
use App\Platform\Notifications\Application\Query\GetInAppNotificationsQuery;
use App\Platform\Notifications\Application\Query\GetNotificationStatusHandler;
use App\Platform\Notifications\Application\Query\GetNotificationStatusQuery;
use App\Platform\Notifications\Application\Query\GetNotificationTemplateHandler;
use App\Platform\Notifications\Application\Query\GetNotificationTemplateQuery;
use App\Platform\Notifications\Authorization\InAppNotificationPolicy;
use App\Platform\Notifications\Authorization\NotificationPolicy;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Platform\Notifications\Domain\Model\InAppNotification;
use App\Platform\Notifications\Domain\Model\NotificationRequest;
use App\Platform\Notifications\Domain\Port\EmailGatewayPort;
use App\Platform\Notifications\Domain\Port\InAppBroadcasterPort;
use App\Platform\Notifications\Domain\Port\PushGatewayPort;
use App\Platform\Notifications\Domain\Port\SmsGatewayPort;
use App\Platform\Notifications\Domain\Repository\ChannelPreferenceRepositoryInterface;
use App\Platform\Notifications\Domain\Repository\DeduplicationRepositoryInterface;
use App\Platform\Notifications\Domain\Repository\InAppNotificationRepositoryInterface;
use App\Platform\Notifications\Domain\Repository\NotificationRequestRepositoryInterface;
use App\Platform\Notifications\Domain\Repository\NotificationTemplateRepositoryInterface;
use App\Platform\Notifications\Infrastructure\Adapter\HttpSmsGateway;
use App\Platform\Notifications\Infrastructure\Adapter\LaravelMailEmailGateway;
use App\Platform\Notifications\Infrastructure\Adapter\ReverbInAppBroadcaster;
use App\Platform\Notifications\Infrastructure\Adapter\SyncEmailGateway;
use App\Platform\Notifications\Infrastructure\Adapter\SyncPushGateway;
use App\Platform\Notifications\Infrastructure\Adapter\SyncSmsGateway;
use App\Platform\Notifications\Infrastructure\Listener\OnAppointmentBooked;
use App\Platform\Notifications\Infrastructure\Listener\OnLabResultVerified;
use App\Platform\Notifications\Infrastructure\Listener\OnMedicationDispensed;
use App\Platform\Notifications\Infrastructure\Repository\EloquentChannelPreferenceRepository;
use App\Platform\Notifications\Infrastructure\Repository\EloquentDeduplicationRepository;
use App\Platform\Notifications\Infrastructure\Repository\EloquentInAppNotificationRepository;
use App\Platform\Notifications\Infrastructure\Repository\EloquentNotificationRequestRepository;
use App\Platform\Notifications\Infrastructure\Repository\EloquentNotificationTemplateRepository;
use App\Platform\Notifications\Infrastructure\Service\NotificationsApiService;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Events\EventRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationRequestRepositoryInterface::class, EloquentNotificationRequestRepository::class);
        $this->app->bind(NotificationTemplateRepositoryInterface::class, EloquentNotificationTemplateRepository::class);
        $this->app->bind(ChannelPreferenceRepositoryInterface::class, EloquentChannelPreferenceRepository::class);
        $this->app->bind(InAppNotificationRepositoryInterface::class, EloquentInAppNotificationRepository::class);
        $this->app->bind(DeduplicationRepositoryInterface::class, EloquentDeduplicationRepository::class);

        $this->app->bind(SmsGatewayPort::class, function (): SmsGatewayPort {
            return config('notifications.sms_driver') === 'http'
                ? new HttpSmsGateway
                : new SyncSmsGateway;
        });

        $this->app->bind(EmailGatewayPort::class, function (): EmailGatewayPort {
            return config('notifications.email_driver') === 'mail'
                ? new LaravelMailEmailGateway
                : new SyncEmailGateway;
        });

        $this->app->bind(PushGatewayPort::class, SyncPushGateway::class);
        $this->app->bind(InAppBroadcasterPort::class, ReverbInAppBroadcaster::class);

        $this->app->bind(NotificationsApi::class, NotificationsApiService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            SendNotificationCommand::class => SendNotificationHandler::class,
            SendBulkNotificationCommand::class => SendBulkNotificationHandler::class,
            CancelPendingNotificationCommand::class => CancelPendingNotificationHandler::class,
            SetChannelPreferenceCommand::class => SetChannelPreferenceHandler::class,
            MarkInAppReadCommand::class => MarkInAppReadHandler::class,
            UpsertNotificationTemplateCommand::class => UpsertNotificationTemplateHandler::class,
        ]);

        QueryBus::map([
            GetNotificationStatusQuery::class => GetNotificationStatusHandler::class,
            GetInAppNotificationsQuery::class => GetInAppNotificationsHandler::class,
            GetChannelPreferenceQuery::class => GetChannelPreferenceHandler::class,
            GetNotificationTemplateQuery::class => GetNotificationTemplateHandler::class,
        ]);

        EventRegistry::listen(AppointmentBooked::class, OnAppointmentBooked::class);
        EventRegistry::listen(LabResultVerified::class, OnLabResultVerified::class);
        EventRegistry::listen(MedicationDispensed::class, OnMedicationDispensed::class);

        Gate::policy(NotificationRequest::class, NotificationPolicy::class);
        Gate::policy(InAppNotification::class, InAppNotificationPolicy::class);
    }
}
