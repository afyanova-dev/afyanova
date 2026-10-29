<?php

declare(strict_types=1);

namespace App\Domains\Laboratory;

use App\Domains\Laboratory\Application\Command\AcceptLabOrder;
use App\Domains\Laboratory\Application\Command\AcceptLabOrderHandler;
use App\Domains\Laboratory\Application\Command\AcceptSpecimen;
use App\Domains\Laboratory\Application\Command\AcceptSpecimenHandler;
use App\Domains\Laboratory\Application\Command\AcknowledgeCriticalResult;
use App\Domains\Laboratory\Application\Command\AcknowledgeCriticalResultHandler;
use App\Domains\Laboratory\Application\Command\AmendResult;
use App\Domains\Laboratory\Application\Command\AmendResultHandler;
use App\Domains\Laboratory\Application\Command\CancelLabOrder;
use App\Domains\Laboratory\Application\Command\CancelLabOrderHandler;
use App\Domains\Laboratory\Application\Command\CancelResult;
use App\Domains\Laboratory\Application\Command\CancelResultHandler;
use App\Domains\Laboratory\Application\Command\CollectSpecimen;
use App\Domains\Laboratory\Application\Command\CollectSpecimenHandler;
use App\Domains\Laboratory\Application\Command\CreateLabOrder;
use App\Domains\Laboratory\Application\Command\CreateLabOrderHandler;
use App\Domains\Laboratory\Application\Command\ReceiveSpecimen;
use App\Domains\Laboratory\Application\Command\ReceiveSpecimenHandler;
use App\Domains\Laboratory\Application\Command\RecordCriticalResult;
use App\Domains\Laboratory\Application\Command\RecordCriticalResultHandler;
use App\Domains\Laboratory\Application\Command\RecordQcRun;
use App\Domains\Laboratory\Application\Command\RecordQcRunHandler;
use App\Domains\Laboratory\Application\Command\RecordResult;
use App\Domains\Laboratory\Application\Command\RecordResultHandler;
use App\Domains\Laboratory\Application\Command\RejectLabOrder;
use App\Domains\Laboratory\Application\Command\RejectLabOrderHandler;
use App\Domains\Laboratory\Application\Command\RejectSpecimen;
use App\Domains\Laboratory\Application\Command\RejectSpecimenHandler;
use App\Domains\Laboratory\Application\Command\ReleaseResult;
use App\Domains\Laboratory\Application\Command\ReleaseResultHandler;
use App\Domains\Laboratory\Application\Command\RequestRecollection;
use App\Domains\Laboratory\Application\Command\RequestRecollectionHandler;
use App\Domains\Laboratory\Application\Command\ReviewQcRun;
use App\Domains\Laboratory\Application\Command\ReviewQcRunHandler;
use App\Domains\Laboratory\Application\Command\ReviewResult;
use App\Domains\Laboratory\Application\Command\ReviewResultHandler;
use App\Domains\Laboratory\Application\Command\SubmitLabOrder;
use App\Domains\Laboratory\Application\Command\SubmitLabOrderHandler;
use App\Domains\Laboratory\Application\Command\VerifyResult;
use App\Domains\Laboratory\Application\Command\VerifyResultHandler;
use App\Domains\Laboratory\Application\Query\GetCriticalResult;
use App\Domains\Laboratory\Application\Query\GetCriticalResultHandler;
use App\Domains\Laboratory\Application\Query\GetLabOrder;
use App\Domains\Laboratory\Application\Query\GetLabOrderHandler;
use App\Domains\Laboratory\Application\Query\GetLabPanel;
use App\Domains\Laboratory\Application\Query\GetLabPanelHandler;
use App\Domains\Laboratory\Application\Query\GetLabPanels;
use App\Domains\Laboratory\Application\Query\GetLabPanelsHandler;
use App\Domains\Laboratory\Application\Query\GetLabResult;
use App\Domains\Laboratory\Application\Query\GetLabResultHandler;
use App\Domains\Laboratory\Application\Query\GetLabSpecimen;
use App\Domains\Laboratory\Application\Query\GetLabSpecimenHandler;
use App\Domains\Laboratory\Application\Query\GetLabTestDefinition;
use App\Domains\Laboratory\Application\Query\GetLabTestDefinitionHandler;
use App\Domains\Laboratory\Application\Query\GetLabTestDefinitions;
use App\Domains\Laboratory\Application\Query\GetLabTestDefinitionsHandler;
use App\Domains\Laboratory\Application\Query\GetOrderResults;
use App\Domains\Laboratory\Application\Query\GetOrderResultsHandler;
use App\Domains\Laboratory\Application\Query\GetOrderSpecimens;
use App\Domains\Laboratory\Application\Query\GetOrderSpecimensHandler;
use App\Domains\Laboratory\Application\Query\GetPatientLabOrders;
use App\Domains\Laboratory\Application\Query\GetPatientLabOrdersHandler;
use App\Domains\Laboratory\Application\Query\GetPendingCriticalResults;
use App\Domains\Laboratory\Application\Query\GetPendingCriticalResultsHandler;
use App\Domains\Laboratory\Application\Query\GetQcRun;
use App\Domains\Laboratory\Application\Query\GetQcRunHandler;
use App\Domains\Laboratory\Application\Query\GetQcRuns;
use App\Domains\Laboratory\Application\Query\GetQcRunsHandler;
use App\Domains\Laboratory\Application\Query\GetResultAmendments;
use App\Domains\Laboratory\Application\Query\GetResultAmendmentsHandler;
use App\Domains\Laboratory\Application\Query\SearchLabOrders;
use App\Domains\Laboratory\Application\Query\SearchLabOrdersHandler;
use App\Domains\Laboratory\Authorization\LabCriticalResultPolicy;
use App\Domains\Laboratory\Authorization\LabOrderPolicy;
use App\Domains\Laboratory\Authorization\LabQcRunPolicy;
use App\Domains\Laboratory\Authorization\LabResultPolicy;
use App\Domains\Laboratory\Authorization\LabSpecimenPolicy;
use App\Domains\Laboratory\Console\DefineLabPanelCommand;
use App\Domains\Laboratory\Console\DefineLabTestCommand;
use App\Domains\Laboratory\Domain\LabCriticalResultReader;
use App\Domains\Laboratory\Domain\LabCriticalResultRepository;
use App\Domains\Laboratory\Domain\LabOrderReader;
use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\LabPanelReader;
use App\Domains\Laboratory\Domain\LabPanelRepository;
use App\Domains\Laboratory\Domain\LabQcRunReader;
use App\Domains\Laboratory\Domain\LabQcRunRepository;
use App\Domains\Laboratory\Domain\LabResultReader;
use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\LabSpecimenReader;
use App\Domains\Laboratory\Domain\LabSpecimenRepository;
use App\Domains\Laboratory\Domain\LabTestDefinitionReader;
use App\Domains\Laboratory\Domain\LabTestDefinitionRepository;
use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Domains\Laboratory\Infrastructure\Reader\EloquentLabCriticalResultReader;
use App\Domains\Laboratory\Infrastructure\Reader\EloquentLabOrderReader;
use App\Domains\Laboratory\Infrastructure\Reader\EloquentLabPanelReader;
use App\Domains\Laboratory\Infrastructure\Reader\EloquentLabQcRunReader;
use App\Domains\Laboratory\Infrastructure\Reader\EloquentLabResultReader;
use App\Domains\Laboratory\Infrastructure\Reader\EloquentLabSpecimenReader;
use App\Domains\Laboratory\Infrastructure\Reader\EloquentLabTestDefinitionReader;
use App\Domains\Laboratory\Infrastructure\Repository\EloquentLabCriticalResultRepository;
use App\Domains\Laboratory\Infrastructure\Repository\EloquentLabOrderRepository;
use App\Domains\Laboratory\Infrastructure\Repository\EloquentLabPanelRepository;
use App\Domains\Laboratory\Infrastructure\Repository\EloquentLabQcRunRepository;
use App\Domains\Laboratory\Infrastructure\Repository\EloquentLabResultRepository;
use App\Domains\Laboratory\Infrastructure\Repository\EloquentLabSpecimenRepository;
use App\Domains\Laboratory\Infrastructure\Repository\EloquentLabTestDefinitionRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Laboratory module service provider (14-laboratory-domain.md; ADR-157).
 * Lab.1: the test catalog (`LabTestDefinition`, console-authored) + the
 * laboratory order (`LabOrder` + `LabOrderItem`), front of the §24 lifecycle.
 */
final class LaboratoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LabOrderRepository::class, EloquentLabOrderRepository::class);
        $this->app->bind(LabTestDefinitionRepository::class, EloquentLabTestDefinitionRepository::class);
        $this->app->bind(LabSpecimenRepository::class, EloquentLabSpecimenRepository::class);
        $this->app->bind(LabResultRepository::class, EloquentLabResultRepository::class);
        $this->app->bind(LabCriticalResultRepository::class, EloquentLabCriticalResultRepository::class);
        $this->app->bind(LabPanelRepository::class, EloquentLabPanelRepository::class);
        $this->app->bind(LabQcRunRepository::class, EloquentLabQcRunRepository::class);

        $this->app->bind(LabOrderReader::class, EloquentLabOrderReader::class);
        $this->app->bind(LabTestDefinitionReader::class, EloquentLabTestDefinitionReader::class);
        $this->app->bind(LabSpecimenReader::class, EloquentLabSpecimenReader::class);
        $this->app->bind(LabResultReader::class, EloquentLabResultReader::class);
        $this->app->bind(LabCriticalResultReader::class, EloquentLabCriticalResultReader::class);
        $this->app->bind(LabPanelReader::class, EloquentLabPanelReader::class);
        $this->app->bind(LabQcRunReader::class, EloquentLabQcRunReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DefineLabTestCommand::class,
                DefineLabPanelCommand::class,
            ]);
        }

        CommandBus::map([
            CreateLabOrder::class => CreateLabOrderHandler::class,
            SubmitLabOrder::class => SubmitLabOrderHandler::class,
            AcceptLabOrder::class => AcceptLabOrderHandler::class,
            RejectLabOrder::class => RejectLabOrderHandler::class,
            CancelLabOrder::class => CancelLabOrderHandler::class,
            CollectSpecimen::class => CollectSpecimenHandler::class,
            ReceiveSpecimen::class => ReceiveSpecimenHandler::class,
            AcceptSpecimen::class => AcceptSpecimenHandler::class,
            RejectSpecimen::class => RejectSpecimenHandler::class,
            RequestRecollection::class => RequestRecollectionHandler::class,
            RecordResult::class => RecordResultHandler::class,
            ReviewResult::class => ReviewResultHandler::class,
            VerifyResult::class => VerifyResultHandler::class,
            ReleaseResult::class => ReleaseResultHandler::class,
            AmendResult::class => AmendResultHandler::class,
            CancelResult::class => CancelResultHandler::class,
            RecordCriticalResult::class => RecordCriticalResultHandler::class,
            AcknowledgeCriticalResult::class => AcknowledgeCriticalResultHandler::class,
            RecordQcRun::class => RecordQcRunHandler::class,
            ReviewQcRun::class => ReviewQcRunHandler::class,
        ]);

        QueryBus::map([
            GetLabOrder::class => GetLabOrderHandler::class,
            SearchLabOrders::class => SearchLabOrdersHandler::class,
            GetPatientLabOrders::class => GetPatientLabOrdersHandler::class,
            GetLabTestDefinition::class => GetLabTestDefinitionHandler::class,
            GetLabTestDefinitions::class => GetLabTestDefinitionsHandler::class,
            GetLabPanel::class => GetLabPanelHandler::class,
            GetLabPanels::class => GetLabPanelsHandler::class,
            GetLabSpecimen::class => GetLabSpecimenHandler::class,
            GetOrderSpecimens::class => GetOrderSpecimensHandler::class,
            GetLabResult::class => GetLabResultHandler::class,
            GetOrderResults::class => GetOrderResultsHandler::class,
            GetResultAmendments::class => GetResultAmendmentsHandler::class,
            GetCriticalResult::class => GetCriticalResultHandler::class,
            GetPendingCriticalResults::class => GetPendingCriticalResultsHandler::class,
            GetQcRun::class => GetQcRunHandler::class,
            GetQcRuns::class => GetQcRunsHandler::class,
        ]);

        Gate::policy(LabOrder::class, LabOrderPolicy::class);
        Gate::policy(LabSpecimen::class, LabSpecimenPolicy::class);
        Gate::policy(LabResult::class, LabResultPolicy::class);
        Gate::policy(LabCriticalResult::class, LabCriticalResultPolicy::class);
        Gate::policy(LabQcRun::class, LabQcRunPolicy::class);
    }
}
