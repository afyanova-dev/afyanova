<?php

declare(strict_types=1);

namespace App\Platform\Reporting;

use App\Platform\Reporting\Application\Command\BuildStatutoryReturnCommand;
use App\Platform\Reporting\Application\Command\BuildStatutoryReturnHandler;
use App\Platform\Reporting\Application\Command\DefineMetricCommand;
use App\Platform\Reporting\Application\Command\DefineMetricHandler;
use App\Platform\Reporting\Application\Command\DefineReportDefinitionCommand;
use App\Platform\Reporting\Application\Command\DefineReportDefinitionHandler;
use App\Platform\Reporting\Application\Command\RebuildProjectionCommand;
use App\Platform\Reporting\Application\Command\RebuildProjectionHandler;
use App\Platform\Reporting\Application\Command\RecordReturnAcknowledgementCommand;
use App\Platform\Reporting\Application\Command\RecordReturnAcknowledgementHandler;
use App\Platform\Reporting\Application\Command\ResubmitStatutoryReturnCommand;
use App\Platform\Reporting\Application\Command\ResubmitStatutoryReturnHandler;
use App\Platform\Reporting\Application\Command\ReviewStatutoryReturnCommand;
use App\Platform\Reporting\Application\Command\ReviewStatutoryReturnHandler;
use App\Platform\Reporting\Application\Command\RunReportCommand;
use App\Platform\Reporting\Application\Command\RunReportHandler;
use App\Platform\Reporting\Application\Command\SubmitStatutoryReturnCommand;
use App\Platform\Reporting\Application\Command\SubmitStatutoryReturnHandler;
use App\Platform\Reporting\Application\Query\GetDashboardSummaryHandler;
use App\Platform\Reporting\Application\Query\GetDashboardSummaryQuery;
use App\Platform\Reporting\Application\Query\GetMetricHandler;
use App\Platform\Reporting\Application\Query\GetMetricQuery;
use App\Platform\Reporting\Application\Query\GetProjectionStatusHandler;
use App\Platform\Reporting\Application\Query\GetProjectionStatusQuery;
use App\Platform\Reporting\Application\Query\GetReportDefinitionHandler;
use App\Platform\Reporting\Application\Query\GetReportDefinitionQuery;
use App\Platform\Reporting\Application\Query\GetReportRunHandler;
use App\Platform\Reporting\Application\Query\GetReportRunQuery;
use App\Platform\Reporting\Application\Query\GetStatutoryReturnHandler;
use App\Platform\Reporting\Application\Query\GetStatutoryReturnQuery;
use App\Platform\Reporting\Application\Query\ListMetricsHandler;
use App\Platform\Reporting\Application\Query\ListMetricsQuery;
use App\Platform\Reporting\Application\Query\ListReportDefinitionsHandler;
use App\Platform\Reporting\Application\Query\ListReportDefinitionsQuery;
use App\Platform\Reporting\Application\Query\ListStatutoryReturnsHandler;
use App\Platform\Reporting\Application\Query\ListStatutoryReturnsQuery;
use App\Platform\Reporting\Application\Query\QueryDataMartHandler;
use App\Platform\Reporting\Application\Query\QueryDataMartQuery;
use App\Platform\Reporting\Authorization\ReportingPolicy;
use App\Platform\Reporting\Contracts\ReportingApi;
use App\Platform\Reporting\Domain\Model\StatutoryReturn;
use App\Platform\Reporting\Infrastructure\Console\RebuildProjectionsCommand;
use App\Platform\Reporting\Infrastructure\Console\SeedReportingDefinitionsCommand;
use App\Platform\Reporting\Infrastructure\Service\ReportingApiService;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\ReadModel\ReadModelRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReportingApi::class, ReportingApiService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildProjectionsCommand::class,
                SeedReportingDefinitionsCommand::class,
            ]);
        }

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            DefineMetricCommand::class => DefineMetricHandler::class,
            DefineReportDefinitionCommand::class => DefineReportDefinitionHandler::class,
            RunReportCommand::class => RunReportHandler::class,
            BuildStatutoryReturnCommand::class => BuildStatutoryReturnHandler::class,
            ReviewStatutoryReturnCommand::class => ReviewStatutoryReturnHandler::class,
            SubmitStatutoryReturnCommand::class => SubmitStatutoryReturnHandler::class,
            RecordReturnAcknowledgementCommand::class => RecordReturnAcknowledgementHandler::class,
            ResubmitStatutoryReturnCommand::class => ResubmitStatutoryReturnHandler::class,
            RebuildProjectionCommand::class => RebuildProjectionHandler::class,
        ]);

        QueryBus::map([
            GetDashboardSummaryQuery::class => GetDashboardSummaryHandler::class,
            GetReportDefinitionQuery::class => GetReportDefinitionHandler::class,
            ListReportDefinitionsQuery::class => ListReportDefinitionsHandler::class,
            GetReportRunQuery::class => GetReportRunHandler::class,
            QueryDataMartQuery::class => QueryDataMartHandler::class,
            GetMetricQuery::class => GetMetricHandler::class,
            ListMetricsQuery::class => ListMetricsHandler::class,
            GetStatutoryReturnQuery::class => GetStatutoryReturnHandler::class,
            ListStatutoryReturnsQuery::class => ListStatutoryReturnsHandler::class,
            GetProjectionStatusQuery::class => GetProjectionStatusHandler::class,
        ]);

        Gate::policy(StatutoryReturn::class, ReportingPolicy::class);

        $this->declareReadModels();
    }

    private function declareReadModels(): void
    {
        ReadModelRegistry::declare('reporting_checkpoints', [
            'table' => 'rpt_projection_checkpoints',
            'source' => 'Reporting & Analytics (stream checkpoint tracking)',
            'owner' => 'Reporting',
            'rebuild' => 'system internal',
            'consumers' => ['workspaces/reporting', 'platform/reporting'],
        ]);

        ReadModelRegistry::declare('reporting_metrics', [
            'table' => 'rpt_metric_definitions',
            'source' => 'Reporting & Analytics (governed metrics with owning_domain sign-off)',
            'owner' => 'Reporting',
            'rebuild' => 'system internal',
            'consumers' => ['workspaces/reporting', 'workspaces/clinical', 'workspaces/billing'],
        ]);

        ReadModelRegistry::declare('reporting_data_marts', [
            'table' => 'rpt_data_marts',
            'source' => 'Reporting & Analytics (curated projection data marts)',
            'owner' => 'Reporting',
            'rebuild' => 'system internal',
            'consumers' => ['workspaces/reporting'],
        ]);

        ReadModelRegistry::declare('reporting_definitions', [
            'table' => 'rpt_report_definitions',
            'source' => 'Reporting & Analytics (governed report definitions)',
            'owner' => 'Reporting',
            'rebuild' => 'system internal',
            'consumers' => ['workspaces/reporting'],
        ]);

        ReadModelRegistry::declare('reporting_report_run_counters', [
            'table' => 'rpt_report_run_number_counters',
            'source' => 'Reporting & Analytics (report run number sequence counters)',
            'owner' => 'Reporting',
            'rebuild' => 'system internal',
            'consumers' => ['platform/reporting'],
        ]);

        ReadModelRegistry::declare('reporting_report_runs', [
            'table' => 'rpt_report_runs',
            'source' => 'Reporting & Analytics (executed report runs and datasets)',
            'owner' => 'Reporting',
            'rebuild' => 'system internal',
            'consumers' => ['workspaces/reporting'],
        ]);

        ReadModelRegistry::declare('reporting_return_counters', [
            'table' => 'rpt_return_number_counters',
            'source' => 'Reporting & Analytics (statutory return number sequence counters)',
            'owner' => 'Reporting',
            'rebuild' => 'system internal',
            'consumers' => ['platform/reporting'],
        ]);

        ReadModelRegistry::declare('reporting_statutory_returns', [
            'table' => 'rpt_statutory_returns',
            'source' => 'Reporting & Analytics (immutable statutory returns)',
            'owner' => 'Reporting',
            'rebuild' => 'system internal',
            'consumers' => ['workspaces/reporting', 'platform/integration'],
        ]);

        ReadModelRegistry::declare('reporting_statutory_return_lines', [
            'table' => 'rpt_statutory_return_lines',
            'source' => 'Reporting & Analytics (statutory return indicators and lines)',
            'owner' => 'Reporting',
            'rebuild' => 'system internal',
            'consumers' => ['workspaces/reporting', 'platform/integration'],
        ]);

        ReadModelRegistry::declare('reporting_encounter_daily_aggregates', [
            'table' => 'rpt_encounter_daily_aggregates',
            'source' => 'Encounter (enc_encounters)',
            'owner' => 'Reporting',
            'rebuild' => 'replay enc_encounters from t0',
            'consumers' => ['workspaces/reporting', 'workspaces/reception'],
        ]);

        ReadModelRegistry::declare('reporting_financial_daily_revenue', [
            'table' => 'rpt_financial_daily_revenue',
            'source' => 'Billing (bill_invoices, bill_payments)',
            'owner' => 'Reporting',
            'rebuild' => 'replay bill_invoices from t0',
            'consumers' => ['workspaces/reporting', 'workspaces/billing'],
        ]);

        ReadModelRegistry::declare('reporting_bed_occupancy_daily', [
            'table' => 'rpt_bed_occupancy_daily',
            'source' => 'Bed & Ward Management (bw_beds, bw_bed_assignments)',
            'owner' => 'Reporting',
            'rebuild' => 'replay bw_beds from t0',
            'consumers' => ['workspaces/reporting', 'workspaces/wards'],
        ]);

        ReadModelRegistry::declare('reporting_lab_tat_daily', [
            'table' => 'rpt_lab_tat_daily',
            'source' => 'Laboratory (lab_orders, lab_specimens)',
            'owner' => 'Reporting',
            'rebuild' => 'replay lab_orders from t0',
            'consumers' => ['workspaces/reporting', 'workspaces/laboratory'],
        ]);

        ReadModelRegistry::declare('reporting_pharmacy_daily_aggregates', [
            'table' => 'rpt_pharmacy_daily_aggregates',
            'source' => 'Pharmacy (rx_prescriptions)',
            'owner' => 'Reporting',
            'rebuild' => 'replay rx_prescriptions from t0',
            'consumers' => ['workspaces/reporting', 'workspaces/pharmacy'],
        ]);
    }
}
