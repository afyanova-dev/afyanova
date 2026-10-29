<?php

declare(strict_types=1);

namespace App\Domains\Radiology;

use App\Domains\Radiology\Application\Command\AbortImagingStudy;
use App\Domains\Radiology\Application\Command\AbortImagingStudyHandler;
use App\Domains\Radiology\Application\Command\AcceptImagingOrder;
use App\Domains\Radiology\Application\Command\AcceptImagingOrderHandler;
use App\Domains\Radiology\Application\Command\AcknowledgeCriticalFinding;
use App\Domains\Radiology\Application\Command\AcknowledgeCriticalFindingHandler;
use App\Domains\Radiology\Application\Command\AmendRadiologyReport;
use App\Domains\Radiology\Application\Command\AmendRadiologyReportHandler;
use App\Domains\Radiology\Application\Command\CancelImagingOrder;
use App\Domains\Radiology\Application\Command\CancelImagingOrderHandler;
use App\Domains\Radiology\Application\Command\CancelImagingStudy;
use App\Domains\Radiology\Application\Command\CancelImagingStudyHandler;
use App\Domains\Radiology\Application\Command\CancelRadiologyReport;
use App\Domains\Radiology\Application\Command\CancelRadiologyReportHandler;
use App\Domains\Radiology\Application\Command\CompleteImagingStudy;
use App\Domains\Radiology\Application\Command\CompleteImagingStudyHandler;
use App\Domains\Radiology\Application\Command\CreateImagingOrder;
use App\Domains\Radiology\Application\Command\CreateImagingOrderHandler;
use App\Domains\Radiology\Application\Command\CreateRadiologyReport;
use App\Domains\Radiology\Application\Command\CreateRadiologyReportHandler;
use App\Domains\Radiology\Application\Command\MarkReportReviewed;
use App\Domains\Radiology\Application\Command\MarkReportReviewedHandler;
use App\Domains\Radiology\Application\Command\MarkStudyReady;
use App\Domains\Radiology\Application\Command\MarkStudyReadyHandler;
use App\Domains\Radiology\Application\Command\RecordCriticalFinding;
use App\Domains\Radiology\Application\Command\RecordCriticalFindingHandler;
use App\Domains\Radiology\Application\Command\RecordImagingNoShow;
use App\Domains\Radiology\Application\Command\RecordImagingNoShowHandler;
use App\Domains\Radiology\Application\Command\RejectImagingOrder;
use App\Domains\Radiology\Application\Command\RejectImagingOrderHandler;
use App\Domains\Radiology\Application\Command\ReleaseRadiologyReport;
use App\Domains\Radiology\Application\Command\ReleaseRadiologyReportHandler;
use App\Domains\Radiology\Application\Command\ScheduleImagingStudy;
use App\Domains\Radiology\Application\Command\ScheduleImagingStudyHandler;
use App\Domains\Radiology\Application\Command\StartImagingStudy;
use App\Domains\Radiology\Application\Command\StartImagingStudyHandler;
use App\Domains\Radiology\Application\Command\SubmitImagingOrder;
use App\Domains\Radiology\Application\Command\SubmitImagingOrderHandler;
use App\Domains\Radiology\Application\Command\UpdateRadiologyReport;
use App\Domains\Radiology\Application\Command\UpdateRadiologyReportHandler;
use App\Domains\Radiology\Application\Command\VerifyRadiologyReport;
use App\Domains\Radiology\Application\Command\VerifyRadiologyReportHandler;
use App\Domains\Radiology\Application\Query\GetCriticalFinding;
use App\Domains\Radiology\Application\Query\GetCriticalFindingHandler;
use App\Domains\Radiology\Application\Query\GetImagingModalities;
use App\Domains\Radiology\Application\Query\GetImagingModalitiesHandler;
use App\Domains\Radiology\Application\Query\GetImagingModality;
use App\Domains\Radiology\Application\Query\GetImagingModalityHandler;
use App\Domains\Radiology\Application\Query\GetImagingOrder;
use App\Domains\Radiology\Application\Query\GetImagingOrderHandler;
use App\Domains\Radiology\Application\Query\GetImagingServiceDefinition;
use App\Domains\Radiology\Application\Query\GetImagingServiceDefinitionHandler;
use App\Domains\Radiology\Application\Query\GetImagingServices;
use App\Domains\Radiology\Application\Query\GetImagingServicesHandler;
use App\Domains\Radiology\Application\Query\GetImagingStudy;
use App\Domains\Radiology\Application\Query\GetImagingStudyHandler;
use App\Domains\Radiology\Application\Query\GetOrderStudies;
use App\Domains\Radiology\Application\Query\GetOrderStudiesHandler;
use App\Domains\Radiology\Application\Query\GetPatientImagingOrders;
use App\Domains\Radiology\Application\Query\GetPatientImagingOrdersHandler;
use App\Domains\Radiology\Application\Query\GetPendingCriticalFindings;
use App\Domains\Radiology\Application\Query\GetPendingCriticalFindingsHandler;
use App\Domains\Radiology\Application\Query\GetPendingReports;
use App\Domains\Radiology\Application\Query\GetPendingReportsHandler;
use App\Domains\Radiology\Application\Query\GetRadiologyReport;
use App\Domains\Radiology\Application\Query\GetRadiologyReportHandler;
use App\Domains\Radiology\Application\Query\GetRadiologyWorklist;
use App\Domains\Radiology\Application\Query\GetRadiologyWorklistHandler;
use App\Domains\Radiology\Application\Query\GetReportAmendments;
use App\Domains\Radiology\Application\Query\GetReportAmendmentsHandler;
use App\Domains\Radiology\Application\Query\GetStudyReport;
use App\Domains\Radiology\Application\Query\GetStudyReportHandler;
use App\Domains\Radiology\Application\Query\SearchImagingOrders;
use App\Domains\Radiology\Application\Query\SearchImagingOrdersHandler;
use App\Domains\Radiology\Authorization\ImagingOrderPolicy;
use App\Domains\Radiology\Authorization\ImagingStudyPolicy;
use App\Domains\Radiology\Authorization\RadiologyCriticalFindingPolicy;
use App\Domains\Radiology\Authorization\RadiologyReportPolicy;
use App\Domains\Radiology\Console\DefineImagingServiceCommand;
use App\Domains\Radiology\Console\DefineModalityCommand;
use App\Domains\Radiology\Domain\ImagingModalityReader;
use App\Domains\Radiology\Domain\ImagingModalityRepository;
use App\Domains\Radiology\Domain\ImagingOrderReader;
use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\ImagingServiceReader;
use App\Domains\Radiology\Domain\ImagingServiceRepository;
use App\Domains\Radiology\Domain\ImagingStudyReader;
use App\Domains\Radiology\Domain\ImagingStudyRepository;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\RadiologyCriticalFindingReader;
use App\Domains\Radiology\Domain\RadiologyCriticalFindingRepository;
use App\Domains\Radiology\Domain\RadiologyReportReader;
use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Domains\Radiology\Infrastructure\Reader\EloquentImagingModalityReader;
use App\Domains\Radiology\Infrastructure\Reader\EloquentImagingOrderReader;
use App\Domains\Radiology\Infrastructure\Reader\EloquentImagingServiceReader;
use App\Domains\Radiology\Infrastructure\Reader\EloquentImagingStudyReader;
use App\Domains\Radiology\Infrastructure\Reader\EloquentRadiologyCriticalFindingReader;
use App\Domains\Radiology\Infrastructure\Reader\EloquentRadiologyReportReader;
use App\Domains\Radiology\Infrastructure\Repository\EloquentImagingModalityRepository;
use App\Domains\Radiology\Infrastructure\Repository\EloquentImagingOrderRepository;
use App\Domains\Radiology\Infrastructure\Repository\EloquentImagingServiceRepository;
use App\Domains\Radiology\Infrastructure\Repository\EloquentImagingStudyRepository;
use App\Domains\Radiology\Infrastructure\Repository\EloquentRadiologyCriticalFindingRepository;
use App\Domains\Radiology\Infrastructure\Repository\EloquentRadiologyReportRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Radiology module service provider (15-radiology-domain.md; ADR-165…169).
 * Rad.1: the modality + imaging-service catalogs (console-authored) + the
 * imaging order. Rad.2: the imaging study (§104 machine) + the pre-reserved
 * order-state rollup. Rad.3a/b/c: the radiology report (§103), the
 * `rad_report_amendments` append-only child, and the radiology critical finding
 * (§167) + the pre-reserved report-side order rollup.
 */
final class RadiologyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ImagingOrderRepository::class, EloquentImagingOrderRepository::class);
        $this->app->bind(ImagingModalityRepository::class, EloquentImagingModalityRepository::class);
        $this->app->bind(ImagingServiceRepository::class, EloquentImagingServiceRepository::class);
        $this->app->bind(ImagingStudyRepository::class, EloquentImagingStudyRepository::class);
        $this->app->bind(RadiologyReportRepository::class, EloquentRadiologyReportRepository::class);
        $this->app->bind(RadiologyCriticalFindingRepository::class, EloquentRadiologyCriticalFindingRepository::class);

        $this->app->bind(ImagingOrderReader::class, EloquentImagingOrderReader::class);
        $this->app->bind(ImagingModalityReader::class, EloquentImagingModalityReader::class);
        $this->app->bind(ImagingServiceReader::class, EloquentImagingServiceReader::class);
        $this->app->bind(ImagingStudyReader::class, EloquentImagingStudyReader::class);
        $this->app->bind(RadiologyReportReader::class, EloquentRadiologyReportReader::class);
        $this->app->bind(RadiologyCriticalFindingReader::class, EloquentRadiologyCriticalFindingReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DefineModalityCommand::class,
                DefineImagingServiceCommand::class,
            ]);
        }

        CommandBus::map([
            CreateImagingOrder::class => CreateImagingOrderHandler::class,
            SubmitImagingOrder::class => SubmitImagingOrderHandler::class,
            AcceptImagingOrder::class => AcceptImagingOrderHandler::class,
            RejectImagingOrder::class => RejectImagingOrderHandler::class,
            CancelImagingOrder::class => CancelImagingOrderHandler::class,
            ScheduleImagingStudy::class => ScheduleImagingStudyHandler::class,
            MarkStudyReady::class => MarkStudyReadyHandler::class,
            StartImagingStudy::class => StartImagingStudyHandler::class,
            CompleteImagingStudy::class => CompleteImagingStudyHandler::class,
            CancelImagingStudy::class => CancelImagingStudyHandler::class,
            RecordImagingNoShow::class => RecordImagingNoShowHandler::class,
            AbortImagingStudy::class => AbortImagingStudyHandler::class,
            CreateRadiologyReport::class => CreateRadiologyReportHandler::class,
            UpdateRadiologyReport::class => UpdateRadiologyReportHandler::class,
            MarkReportReviewed::class => MarkReportReviewedHandler::class,
            VerifyRadiologyReport::class => VerifyRadiologyReportHandler::class,
            ReleaseRadiologyReport::class => ReleaseRadiologyReportHandler::class,
            AmendRadiologyReport::class => AmendRadiologyReportHandler::class,
            CancelRadiologyReport::class => CancelRadiologyReportHandler::class,
            RecordCriticalFinding::class => RecordCriticalFindingHandler::class,
            AcknowledgeCriticalFinding::class => AcknowledgeCriticalFindingHandler::class,
        ]);

        QueryBus::map([
            GetImagingOrder::class => GetImagingOrderHandler::class,
            SearchImagingOrders::class => SearchImagingOrdersHandler::class,
            GetPatientImagingOrders::class => GetPatientImagingOrdersHandler::class,
            GetImagingModality::class => GetImagingModalityHandler::class,
            GetImagingModalities::class => GetImagingModalitiesHandler::class,
            GetImagingServiceDefinition::class => GetImagingServiceDefinitionHandler::class,
            GetImagingServices::class => GetImagingServicesHandler::class,
            GetImagingStudy::class => GetImagingStudyHandler::class,
            GetOrderStudies::class => GetOrderStudiesHandler::class,
            GetRadiologyWorklist::class => GetRadiologyWorklistHandler::class,
            GetRadiologyReport::class => GetRadiologyReportHandler::class,
            GetStudyReport::class => GetStudyReportHandler::class,
            GetPendingReports::class => GetPendingReportsHandler::class,
            GetReportAmendments::class => GetReportAmendmentsHandler::class,
            GetCriticalFinding::class => GetCriticalFindingHandler::class,
            GetPendingCriticalFindings::class => GetPendingCriticalFindingsHandler::class,
        ]);

        Gate::policy(ImagingOrder::class, ImagingOrderPolicy::class);
        Gate::policy(ImagingStudy::class, ImagingStudyPolicy::class);
        Gate::policy(RadiologyReport::class, RadiologyReportPolicy::class);
        Gate::policy(RadiologyCriticalFinding::class, RadiologyCriticalFindingPolicy::class);
    }
}
