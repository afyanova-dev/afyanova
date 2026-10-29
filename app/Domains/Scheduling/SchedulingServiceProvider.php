<?php

declare(strict_types=1);

namespace App\Domains\Scheduling;

use App\Domains\Scheduling\Application\Command\AcceptWaitlistOffer;
use App\Domains\Scheduling\Application\Command\AcceptWaitlistOfferHandler;
use App\Domains\Scheduling\Application\Command\AddScheduleException;
use App\Domains\Scheduling\Application\Command\AddScheduleExceptionHandler;
use App\Domains\Scheduling\Application\Command\AddToWaitlist;
use App\Domains\Scheduling\Application\Command\AddToWaitlistHandler;
use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Domains\Scheduling\Application\Command\BookAppointmentHandler;
use App\Domains\Scheduling\Application\Command\CancelAppointment;
use App\Domains\Scheduling\Application\Command\CancelAppointmentHandler;
use App\Domains\Scheduling\Application\Command\CancelWaitlistEntry;
use App\Domains\Scheduling\Application\Command\CancelWaitlistEntryHandler;
use App\Domains\Scheduling\Application\Command\ConfirmAppointment;
use App\Domains\Scheduling\Application\Command\ConfirmAppointmentHandler;
use App\Domains\Scheduling\Application\Command\DeclineWaitlistOffer;
use App\Domains\Scheduling\Application\Command\DeclineWaitlistOfferHandler;
use App\Domains\Scheduling\Application\Command\DefineAppointmentType;
use App\Domains\Scheduling\Application\Command\DefineAppointmentTypeHandler;
use App\Domains\Scheduling\Application\Command\DefineSchedule;
use App\Domains\Scheduling\Application\Command\DefineScheduleHandler;
use App\Domains\Scheduling\Application\Command\ExpireLapsedWaitlistOffers;
use App\Domains\Scheduling\Application\Command\ExpireLapsedWaitlistOffersHandler;
use App\Domains\Scheduling\Application\Command\ExpireSlotHold;
use App\Domains\Scheduling\Application\Command\ExpireSlotHoldHandler;
use App\Domains\Scheduling\Application\Command\GenerateSlots;
use App\Domains\Scheduling\Application\Command\GenerateSlotsHandler;
use App\Domains\Scheduling\Application\Command\HoldSlot;
use App\Domains\Scheduling\Application\Command\HoldSlotHandler;
use App\Domains\Scheduling\Application\Command\MarkAppointmentNoShow;
use App\Domains\Scheduling\Application\Command\MarkAppointmentNoShowHandler;
use App\Domains\Scheduling\Application\Command\OfferSlotToWaitlist;
use App\Domains\Scheduling\Application\Command\OfferSlotToWaitlistHandler;
use App\Domains\Scheduling\Application\Command\RecordAppointmentArrival;
use App\Domains\Scheduling\Application\Command\RecordAppointmentArrivalHandler;
use App\Domains\Scheduling\Application\Command\RegisterSchedulableResource;
use App\Domains\Scheduling\Application\Command\RegisterSchedulableResourceHandler;
use App\Domains\Scheduling\Application\Command\ReleaseSlotHold;
use App\Domains\Scheduling\Application\Command\ReleaseSlotHoldHandler;
use App\Domains\Scheduling\Application\Command\RescheduleAppointment;
use App\Domains\Scheduling\Application\Command\RescheduleAppointmentHandler;
use App\Domains\Scheduling\Application\Query\GetAppointment;
use App\Domains\Scheduling\Application\Query\GetAppointmentHandler;
use App\Domains\Scheduling\Application\Query\GetAppointmentTypes;
use App\Domains\Scheduling\Application\Query\GetAppointmentTypesHandler;
use App\Domains\Scheduling\Application\Query\GetAvailableSlots;
use App\Domains\Scheduling\Application\Query\GetAvailableSlotsHandler;
use App\Domains\Scheduling\Application\Query\GetDailyFacilityAppointments;
use App\Domains\Scheduling\Application\Query\GetDailyFacilityAppointmentsHandler;
use App\Domains\Scheduling\Application\Query\GetFacilityAppointmentCalendarHandler;
use App\Domains\Scheduling\Application\Query\GetFacilityAppointmentCalendarQuery;
use App\Domains\Scheduling\Application\Query\GetFacilityWaitlist;
use App\Domains\Scheduling\Application\Query\GetFacilityWaitlistHandler;
use App\Domains\Scheduling\Application\Query\GetPatientAppointments;
use App\Domains\Scheduling\Application\Query\GetPatientAppointmentsHandler;
use App\Domains\Scheduling\Application\Query\GetPatientWaitlist;
use App\Domains\Scheduling\Application\Query\GetPatientWaitlistHandler;
use App\Domains\Scheduling\Application\Query\GetResourceAppointments;
use App\Domains\Scheduling\Application\Query\GetResourceAppointmentsHandler;
use App\Domains\Scheduling\Application\Query\GetResourceSchedule;
use App\Domains\Scheduling\Application\Query\GetResourceScheduleHandler;
use App\Domains\Scheduling\Application\Query\GetSchedulableResources;
use App\Domains\Scheduling\Application\Query\GetSchedulableResourcesHandler;
use App\Domains\Scheduling\Application\Query\GetWaitlistEntry;
use App\Domains\Scheduling\Application\Query\GetWaitlistEntryHandler;
use App\Domains\Scheduling\Application\SchedulingApiService;
use App\Domains\Scheduling\Authorization\AppointmentPolicy;
use App\Domains\Scheduling\Authorization\AppointmentTypePolicy;
use App\Domains\Scheduling\Authorization\SchedulableResourcePolicy;
use App\Domains\Scheduling\Authorization\ScheduleExceptionPolicy;
use App\Domains\Scheduling\Authorization\SchedulePolicy;
use App\Domains\Scheduling\Authorization\SlotPolicy;
use App\Domains\Scheduling\Authorization\WaitlistPolicy;
use App\Domains\Scheduling\Console\ExpireSlotHoldsCommand;
use App\Domains\Scheduling\Console\ExpireWaitlistOffersCommand;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Domains\Scheduling\Domain\AppointmentReader;
use App\Domains\Scheduling\Domain\AppointmentRepository;
use App\Domains\Scheduling\Domain\AppointmentTypeReader;
use App\Domains\Scheduling\Domain\AppointmentTypeRepository;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Domains\Scheduling\Domain\Model\AppointmentType;
use App\Domains\Scheduling\Domain\Model\SchedulableResource;
use App\Domains\Scheduling\Domain\Model\Schedule;
use App\Domains\Scheduling\Domain\Model\ScheduleException;
use App\Domains\Scheduling\Domain\Model\Slot;
use App\Domains\Scheduling\Domain\Model\WaitlistEntry;
use App\Domains\Scheduling\Domain\SchedulableResourceReader;
use App\Domains\Scheduling\Domain\SchedulableResourceRepository;
use App\Domains\Scheduling\Domain\ScheduleExceptionReader;
use App\Domains\Scheduling\Domain\ScheduleExceptionRepository;
use App\Domains\Scheduling\Domain\ScheduleReader;
use App\Domains\Scheduling\Domain\ScheduleRepository;
use App\Domains\Scheduling\Domain\SlotReader;
use App\Domains\Scheduling\Domain\SlotRepository;
use App\Domains\Scheduling\Domain\WaitlistEntryReader;
use App\Domains\Scheduling\Domain\WaitlistEntryRepository;
use App\Domains\Scheduling\Infrastructure\Reader\EloquentAppointmentReader;
use App\Domains\Scheduling\Infrastructure\Reader\EloquentAppointmentTypeReader;
use App\Domains\Scheduling\Infrastructure\Reader\EloquentSchedulableResourceReader;
use App\Domains\Scheduling\Infrastructure\Reader\EloquentScheduleExceptionReader;
use App\Domains\Scheduling\Infrastructure\Reader\EloquentScheduleReader;
use App\Domains\Scheduling\Infrastructure\Reader\EloquentSlotReader;
use App\Domains\Scheduling\Infrastructure\Reader\EloquentWaitlistEntryReader;
use App\Domains\Scheduling\Infrastructure\Repository\EloquentAppointmentRepository;
use App\Domains\Scheduling\Infrastructure\Repository\EloquentAppointmentTypeRepository;
use App\Domains\Scheduling\Infrastructure\Repository\EloquentSchedulableResourceRepository;
use App\Domains\Scheduling\Infrastructure\Repository\EloquentScheduleExceptionRepository;
use App\Domains\Scheduling\Infrastructure\Repository\EloquentScheduleRepository;
use App\Domains\Scheduling\Infrastructure\Repository\EloquentSlotRepository;
use App\Domains\Scheduling\Infrastructure\Repository\EloquentWaitlistEntryRepository;
use App\Domains\Scheduling\Infrastructure\Sequence\AppointmentNumberSequence;
use App\Domains\Scheduling\Infrastructure\Sequence\WaitlistNumberSequence;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for Domain 22 (Scheduling & Appointments).
 */
final class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Repositories
        $this->app->bind(AppointmentTypeRepository::class, EloquentAppointmentTypeRepository::class);
        $this->app->bind(SchedulableResourceRepository::class, EloquentSchedulableResourceRepository::class);
        $this->app->bind(ScheduleRepository::class, EloquentScheduleRepository::class);
        $this->app->bind(ScheduleExceptionRepository::class, EloquentScheduleExceptionRepository::class);
        $this->app->bind(SlotRepository::class, EloquentSlotRepository::class);
        $this->app->bind(AppointmentRepository::class, EloquentAppointmentRepository::class);
        $this->app->bind(WaitlistEntryRepository::class, EloquentWaitlistEntryRepository::class);

        // Readers
        $this->app->bind(AppointmentTypeReader::class, EloquentAppointmentTypeReader::class);
        $this->app->bind(SchedulableResourceReader::class, EloquentSchedulableResourceReader::class);
        $this->app->bind(ScheduleReader::class, EloquentScheduleReader::class);
        $this->app->bind(ScheduleExceptionReader::class, EloquentScheduleExceptionReader::class);
        $this->app->bind(SlotReader::class, EloquentSlotReader::class);
        $this->app->bind(AppointmentReader::class, EloquentAppointmentReader::class);
        $this->app->bind(WaitlistEntryReader::class, EloquentWaitlistEntryReader::class);

        // Sequences
        $this->app->singleton(AppointmentNumberSequence::class);
        $this->app->singleton(WaitlistNumberSequence::class);

        // Public Cross-Domain API Port
        $this->app->bind(SchedulingApi::class, SchedulingApiService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            DefineAppointmentType::class => DefineAppointmentTypeHandler::class,
            RegisterSchedulableResource::class => RegisterSchedulableResourceHandler::class,
            DefineSchedule::class => DefineScheduleHandler::class,
            AddScheduleException::class => AddScheduleExceptionHandler::class,
            GenerateSlots::class => GenerateSlotsHandler::class,
            HoldSlot::class => HoldSlotHandler::class,
            ReleaseSlotHold::class => ReleaseSlotHoldHandler::class,
            BookAppointment::class => BookAppointmentHandler::class,
            ConfirmAppointment::class => ConfirmAppointmentHandler::class,
            RecordAppointmentArrival::class => RecordAppointmentArrivalHandler::class,
            RescheduleAppointment::class => RescheduleAppointmentHandler::class,
            CancelAppointment::class => CancelAppointmentHandler::class,
            MarkAppointmentNoShow::class => MarkAppointmentNoShowHandler::class,
            AddToWaitlist::class => AddToWaitlistHandler::class,
            OfferSlotToWaitlist::class => OfferSlotToWaitlistHandler::class,
            AcceptWaitlistOffer::class => AcceptWaitlistOfferHandler::class,
            DeclineWaitlistOffer::class => DeclineWaitlistOfferHandler::class,
            CancelWaitlistEntry::class => CancelWaitlistEntryHandler::class,
            ExpireLapsedWaitlistOffers::class => ExpireLapsedWaitlistOffersHandler::class,
            ExpireSlotHold::class => ExpireSlotHoldHandler::class,
        ]);

        QueryBus::map([
            GetAppointmentTypes::class => GetAppointmentTypesHandler::class,
            GetSchedulableResources::class => GetSchedulableResourcesHandler::class,
            GetResourceSchedule::class => GetResourceScheduleHandler::class,
            GetAvailableSlots::class => GetAvailableSlotsHandler::class,
            GetAppointment::class => GetAppointmentHandler::class,
            GetDailyFacilityAppointments::class => GetDailyFacilityAppointmentsHandler::class,
            GetFacilityAppointmentCalendarQuery::class => GetFacilityAppointmentCalendarHandler::class,
            GetPatientAppointments::class => GetPatientAppointmentsHandler::class,
            GetResourceAppointments::class => GetResourceAppointmentsHandler::class,
            GetWaitlistEntry::class => GetWaitlistEntryHandler::class,
            GetFacilityWaitlist::class => GetFacilityWaitlistHandler::class,
            GetPatientWaitlist::class => GetPatientWaitlistHandler::class,
        ]);

        Gate::policy(AppointmentType::class, AppointmentTypePolicy::class);
        Gate::policy(SchedulableResource::class, SchedulableResourcePolicy::class);
        Gate::policy(Schedule::class, SchedulePolicy::class);
        Gate::policy(ScheduleException::class, ScheduleExceptionPolicy::class);
        Gate::policy(Slot::class, SlotPolicy::class);
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(WaitlistEntry::class, WaitlistPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireSlotHoldsCommand::class,
                ExpireWaitlistOffersCommand::class,
            ]);
        }
    }
}
