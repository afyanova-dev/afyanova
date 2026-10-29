<?php

declare(strict_types=1);

namespace App\Platform\Queueing;

use App\Domains\Billing\Contracts\Event\PaymentRecorded;
use App\Domains\Clinical\Contracts\Event\ProcedureAuthorized;
use App\Domains\Clinical\Contracts\Event\TriageAssessmentRecorded;
use App\Domains\Encounter\Contracts\Event\EncounterCancelled;
use App\Domains\Encounter\Contracts\Event\EncounterCompleted;
use App\Platform\Queueing\Application\Command\CallNextTicketCommand;
use App\Platform\Queueing\Application\Command\CallNextTicketHandler;
use App\Platform\Queueing\Application\Command\CallTicketCommand;
use App\Platform\Queueing\Application\Command\CallTicketHandler;
use App\Platform\Queueing\Application\Command\CancelEncounterQueueEntriesCommand;
use App\Platform\Queueing\Application\Command\CancelEncounterQueueEntriesHandler;
use App\Platform\Queueing\Application\Command\CloseQueueCommand;
use App\Platform\Queueing\Application\Command\CloseQueueHandler;
use App\Platform\Queueing\Application\Command\CompleteTicketServiceCommand;
use App\Platform\Queueing\Application\Command\CompleteTicketServiceHandler;
use App\Platform\Queueing\Application\Command\PauseQueueCommand;
use App\Platform\Queueing\Application\Command\PauseQueueHandler;
use App\Platform\Queueing\Application\Command\PlaceEncounterInQueueCommand;
use App\Platform\Queueing\Application\Command\PlaceEncounterInQueueHandler;
use App\Platform\Queueing\Application\Command\PlaceQueueEntryOnHoldCommand;
use App\Platform\Queueing\Application\Command\PlaceQueueEntryOnHoldHandler;
use App\Platform\Queueing\Application\Command\RecallTicketCommand;
use App\Platform\Queueing\Application\Command\RecallTicketHandler;
use App\Platform\Queueing\Application\Command\RecordLeftWithoutBeingSeenCommand;
use App\Platform\Queueing\Application\Command\RecordLeftWithoutBeingSeenHandler;
use App\Platform\Queueing\Application\Command\RecordPatientMovementCommand;
use App\Platform\Queueing\Application\Command\RecordPatientMovementHandler;
use App\Platform\Queueing\Application\Command\RecordTicketNoResponseCommand;
use App\Platform\Queueing\Application\Command\RecordTicketNoResponseHandler;
use App\Platform\Queueing\Application\Command\RegisterQueueCommand;
use App\Platform\Queueing\Application\Command\RegisterQueueHandler;
use App\Platform\Queueing\Application\Command\ReleasePatientFinancialHoldCommand;
use App\Platform\Queueing\Application\Command\ReleasePatientFinancialHoldHandler;
use App\Platform\Queueing\Application\Command\ReleaseQueueEntryHoldCommand;
use App\Platform\Queueing\Application\Command\ReleaseQueueEntryHoldHandler;
use App\Platform\Queueing\Application\Command\ResumeQueueCommand;
use App\Platform\Queueing\Application\Command\ResumeQueueHandler;
use App\Platform\Queueing\Application\Command\RouteAuthorizedProcedureToQueue;
use App\Platform\Queueing\Application\Command\RouteAuthorizedProcedureToQueueHandler;
use App\Platform\Queueing\Application\Command\SkipTicketCommand;
use App\Platform\Queueing\Application\Command\SkipTicketHandler;
use App\Platform\Queueing\Application\Command\StartTicketServiceCommand;
use App\Platform\Queueing\Application\Command\StartTicketServiceHandler;
use App\Platform\Queueing\Application\Command\TransferQueueEntryCommand;
use App\Platform\Queueing\Application\Command\TransferQueueEntryHandler;
use App\Platform\Queueing\Application\Command\UpdateTicketPriorityCommand;
use App\Platform\Queueing\Application\Command\UpdateTicketPriorityHandler;
use App\Platform\Queueing\Application\Query\GetCurrentPatientLocationHandler;
use App\Platform\Queueing\Application\Query\GetCurrentPatientLocationQuery;
use App\Platform\Queueing\Application\Query\GetEncounterActiveQueueEntryHandler;
use App\Platform\Queueing\Application\Query\GetEncounterActiveQueueEntryQuery;
use App\Platform\Queueing\Application\Query\GetFacilityWaitingEntriesHandler;
use App\Platform\Queueing\Application\Query\GetFacilityWaitingEntriesQuery;
use App\Platform\Queueing\Application\Query\GetOpenTicketEntries;
use App\Platform\Queueing\Application\Query\GetOpenTicketEntriesHandler;
use App\Platform\Queueing\Application\Query\GetPatientMovementsHandler;
use App\Platform\Queueing\Application\Query\GetPatientMovementsQuery;
use App\Platform\Queueing\Application\Query\GetQueueCallingBoardHandler;
use App\Platform\Queueing\Application\Query\GetQueueCallingBoardQuery;
use App\Platform\Queueing\Application\Query\GetQueueEntryHandler;
use App\Platform\Queueing\Application\Query\GetQueueEntryQuery;
use App\Platform\Queueing\Application\Query\GetQueueHandler;
use App\Platform\Queueing\Application\Query\GetQueuePositionHandler;
use App\Platform\Queueing\Application\Query\GetQueuePositionQuery;
use App\Platform\Queueing\Application\Query\GetQueueQuery;
use App\Platform\Queueing\Application\Query\GetQueueStatusBreakdownHandler;
use App\Platform\Queueing\Application\Query\GetQueueStatusBreakdownQuery;
use App\Platform\Queueing\Application\Query\GetQueueWaitMetricsHandler;
use App\Platform\Queueing\Application\Query\GetQueueWaitMetricsQuery;
use App\Platform\Queueing\Application\Query\ListFacilityQueuesHandler;
use App\Platform\Queueing\Application\Query\ListFacilityQueuesQuery;
use App\Platform\Queueing\Authorization\QueueEntryPolicy;
use App\Platform\Queueing\Authorization\QueuePolicy;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\PatientMovementRepository;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Platform\Queueing\Domain\Repository\TicketCounterRepository;
use App\Platform\Queueing\Infrastructure\Listener\OnEncounterCancelled;
use App\Platform\Queueing\Infrastructure\Listener\OnEncounterCompleted;
use App\Platform\Queueing\Infrastructure\Listener\OnPaymentRecorded;
use App\Platform\Queueing\Infrastructure\Listener\OnProcedureAuthorized;
use App\Platform\Queueing\Infrastructure\Listener\OnTriageAssessmentRecorded;
use App\Platform\Queueing\Infrastructure\Repository\EloquentPatientMovementRepository;
use App\Platform\Queueing\Infrastructure\Repository\EloquentQueueEntryRepository;
use App\Platform\Queueing\Infrastructure\Repository\EloquentQueueRepository;
use App\Platform\Queueing\Infrastructure\Repository\EloquentTicketCounterRepository;
use App\Platform\Queueing\Infrastructure\Service\QueueingApiService;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Events\EventRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class QueueingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QueueRepository::class, EloquentQueueRepository::class);
        $this->app->bind(QueueEntryRepository::class, EloquentQueueEntryRepository::class);
        $this->app->bind(TicketCounterRepository::class, EloquentTicketCounterRepository::class);
        $this->app->bind(PatientMovementRepository::class, EloquentPatientMovementRepository::class);

        $this->app->singleton(Domain\Port\QueueCallingBroadcasterPort::class, Infrastructure\Adapter\ReverbQueueCallingBroadcaster::class);
        $this->app->singleton(Infrastructure\Adapter\ReverbQueueCallingBroadcaster::class);

        $this->app->bind(QueueingApi::class, QueueingApiService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            RegisterQueueCommand::class => RegisterQueueHandler::class,
            PlaceEncounterInQueueCommand::class => PlaceEncounterInQueueHandler::class,
            CallNextTicketCommand::class => CallNextTicketHandler::class,
            CallTicketCommand::class => CallTicketHandler::class,
            RecallTicketCommand::class => RecallTicketHandler::class,
            StartTicketServiceCommand::class => StartTicketServiceHandler::class,
            CompleteTicketServiceCommand::class => CompleteTicketServiceHandler::class,
            PlaceQueueEntryOnHoldCommand::class => PlaceQueueEntryOnHoldHandler::class,
            ReleaseQueueEntryHoldCommand::class => ReleaseQueueEntryHoldHandler::class,
            RecordTicketNoResponseCommand::class => RecordTicketNoResponseHandler::class,
            RecordLeftWithoutBeingSeenCommand::class => RecordLeftWithoutBeingSeenHandler::class,
            SkipTicketCommand::class => SkipTicketHandler::class,
            PauseQueueCommand::class => PauseQueueHandler::class,
            ResumeQueueCommand::class => ResumeQueueHandler::class,
            CloseQueueCommand::class => CloseQueueHandler::class,
            RecordPatientMovementCommand::class => RecordPatientMovementHandler::class,
            TransferQueueEntryCommand::class => TransferQueueEntryHandler::class,
            CancelEncounterQueueEntriesCommand::class => CancelEncounterQueueEntriesHandler::class,
            UpdateTicketPriorityCommand::class => UpdateTicketPriorityHandler::class,
            ReleasePatientFinancialHoldCommand::class => ReleasePatientFinancialHoldHandler::class,
            RouteAuthorizedProcedureToQueue::class => RouteAuthorizedProcedureToQueueHandler::class,
        ]);

        QueryBus::map([
            GetQueueQuery::class => GetQueueHandler::class,
            ListFacilityQueuesQuery::class => ListFacilityQueuesHandler::class,
            GetQueueCallingBoardQuery::class => GetQueueCallingBoardHandler::class,
            Application\Query\GetFacilityFlowBoardQuery::class => Application\Query\GetFacilityFlowBoardHandler::class,
            GetEncounterActiveQueueEntryQuery::class => GetEncounterActiveQueueEntryHandler::class,
            GetQueueEntryQuery::class => GetQueueEntryHandler::class,
            GetCurrentPatientLocationQuery::class => GetCurrentPatientLocationHandler::class,
            GetPatientMovementsQuery::class => GetPatientMovementsHandler::class,
            GetQueuePositionQuery::class => GetQueuePositionHandler::class,
            GetQueueWaitMetricsQuery::class => GetQueueWaitMetricsHandler::class,
            GetQueueStatusBreakdownQuery::class => GetQueueStatusBreakdownHandler::class,
            GetFacilityWaitingEntriesQuery::class => GetFacilityWaitingEntriesHandler::class,
            GetOpenTicketEntries::class => GetOpenTicketEntriesHandler::class,
        ]);

        EventRegistry::listen(EncounterCompleted::class, OnEncounterCompleted::class);
        EventRegistry::listen(EncounterCancelled::class, OnEncounterCancelled::class);
        EventRegistry::listen(TriageAssessmentRecorded::class, OnTriageAssessmentRecorded::class);
        EventRegistry::listen(PaymentRecorded::class, OnPaymentRecorded::class);
        EventRegistry::listen(ProcedureAuthorized::class, OnProcedureAuthorized::class);

        Gate::policy(Queue::class, QueuePolicy::class);
        Gate::policy(QueueEntry::class, QueueEntryPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\SyncQueueRolePermissionsCommand::class,
            ]);
        }
    }
}
