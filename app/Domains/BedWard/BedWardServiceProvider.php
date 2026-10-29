<?php

declare(strict_types=1);

namespace App\Domains\BedWard;

use App\Domains\BedWard\Application\BedWardApiService;
use App\Domains\BedWard\Application\Command\AssignBed;
use App\Domains\BedWard\Application\Command\AssignBedHandler;
use App\Domains\BedWard\Application\Command\BlockBed;
use App\Domains\BedWard\Application\Command\BlockBedHandler;
use App\Domains\BedWard\Application\Command\CancelBedReservation;
use App\Domains\BedWard\Application\Command\CancelBedReservationHandler;
use App\Domains\BedWard\Application\Command\CorrectOccupancy;
use App\Domains\BedWard\Application\Command\CorrectOccupancyHandler;
use App\Domains\BedWard\Application\Command\CreateBed;
use App\Domains\BedWard\Application\Command\CreateBedHandler;
use App\Domains\BedWard\Application\Command\CreateRoom;
use App\Domains\BedWard\Application\Command\CreateRoomHandler;
use App\Domains\BedWard\Application\Command\CreateWard;
use App\Domains\BedWard\Application\Command\CreateWardHandler;
use App\Domains\BedWard\Application\Command\DecommissionBed;
use App\Domains\BedWard\Application\Command\DecommissionBedHandler;
use App\Domains\BedWard\Application\Command\DecommissionWard;
use App\Domains\BedWard\Application\Command\DecommissionWardHandler;
use App\Domains\BedWard\Application\Command\ExpireBedReservation;
use App\Domains\BedWard\Application\Command\ExpireBedReservationHandler;
use App\Domains\BedWard\Application\Command\HoldBedReservation;
use App\Domains\BedWard\Application\Command\HoldBedReservationHandler;
use App\Domains\BedWard\Application\Command\MarkBedCleaning;
use App\Domains\BedWard\Application\Command\MarkBedCleaningHandler;
use App\Domains\BedWard\Application\Command\MarkBedReady;
use App\Domains\BedWard\Application\Command\MarkBedReadyHandler;
use App\Domains\BedWard\Application\Command\ReleaseBed;
use App\Domains\BedWard\Application\Command\ReleaseBedHandler;
use App\Domains\BedWard\Application\Command\RequestBedReservation;
use App\Domains\BedWard\Application\Command\RequestBedReservationHandler;
use App\Domains\BedWard\Application\Command\TransferBed;
use App\Domains\BedWard\Application\Command\TransferBedHandler;
use App\Domains\BedWard\Application\Command\UnblockBed;
use App\Domains\BedWard\Application\Command\UnblockBedHandler;
use App\Domains\BedWard\Application\Command\UpdateBed;
use App\Domains\BedWard\Application\Command\UpdateBedHandler;
use App\Domains\BedWard\Application\Command\UpdateWard;
use App\Domains\BedWard\Application\Command\UpdateWardHandler;
use App\Domains\BedWard\Application\Query\GetAvailableBeds;
use App\Domains\BedWard\Application\Query\GetAvailableBedsHandler;
use App\Domains\BedWard\Application\Query\GetBed;
use App\Domains\BedWard\Application\Query\GetBedHandler;
use App\Domains\BedWard\Application\Query\GetBedOccupancyHistory;
use App\Domains\BedWard\Application\Query\GetBedOccupancyHistoryHandler;
use App\Domains\BedWard\Application\Query\GetEncounterBed;
use App\Domains\BedWard\Application\Query\GetEncounterBedHandler;
use App\Domains\BedWard\Application\Query\GetFacilityOccupancy;
use App\Domains\BedWard\Application\Query\GetFacilityOccupancyHandler;
use App\Domains\BedWard\Application\Query\GetWard;
use App\Domains\BedWard\Application\Query\GetWardCensus;
use App\Domains\BedWard\Application\Query\GetWardCensusHandler;
use App\Domains\BedWard\Application\Query\GetWardHandler;
use App\Domains\BedWard\Authorization\BedOccupancyPolicy;
use App\Domains\BedWard\Authorization\BedPolicy;
use App\Domains\BedWard\Authorization\BedReservationPolicy;
use App\Domains\BedWard\Authorization\WardPolicy;
use App\Domains\BedWard\Console\ExpireLapsedBedReservationsCommand;
use App\Domains\BedWard\Contracts\BedWardApi;
use App\Domains\BedWard\Domain\BedOccupancyReader;
use App\Domains\BedWard\Domain\BedOccupancyRepository;
use App\Domains\BedWard\Domain\BedReader;
use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\BedReservationRepository;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Domains\BedWard\Domain\WardReader;
use App\Domains\BedWard\Domain\WardRepository;
use App\Domains\BedWard\Infrastructure\Reader\EloquentBedOccupancyReader;
use App\Domains\BedWard\Infrastructure\Reader\EloquentBedReader;
use App\Domains\BedWard\Infrastructure\Reader\EloquentWardReader;
use App\Domains\BedWard\Infrastructure\Repository\EloquentBedOccupancyRepository;
use App\Domains\BedWard\Infrastructure\Repository\EloquentBedRepository;
use App\Domains\BedWard\Infrastructure\Repository\EloquentBedReservationRepository;
use App\Domains\BedWard\Infrastructure\Repository\EloquentWardRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Bed & Ward Management module service provider (24-bed-and-ward-management-domain.md;
 * ADR-147, ADR-148, ADR-149).
 */
final class BedWardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WardRepository::class, EloquentWardRepository::class);
        $this->app->bind(BedRepository::class, EloquentBedRepository::class);
        $this->app->bind(BedOccupancyRepository::class, EloquentBedOccupancyRepository::class);
        $this->app->bind(BedReservationRepository::class, EloquentBedReservationRepository::class);

        $this->app->bind(WardReader::class, EloquentWardReader::class);
        $this->app->bind(BedReader::class, EloquentBedReader::class);
        $this->app->bind(BedOccupancyReader::class, EloquentBedOccupancyReader::class);

        $this->app->bind(BedWardApi::class, BedWardApiService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            CreateWard::class => CreateWardHandler::class,
            UpdateWard::class => UpdateWardHandler::class,
            DecommissionWard::class => DecommissionWardHandler::class,
            CreateRoom::class => CreateRoomHandler::class,
            CreateBed::class => CreateBedHandler::class,
            UpdateBed::class => UpdateBedHandler::class,
            DecommissionBed::class => DecommissionBedHandler::class,
            BlockBed::class => BlockBedHandler::class,
            UnblockBed::class => UnblockBedHandler::class,
            MarkBedCleaning::class => MarkBedCleaningHandler::class,
            MarkBedReady::class => MarkBedReadyHandler::class,
            AssignBed::class => AssignBedHandler::class,
            ReleaseBed::class => ReleaseBedHandler::class,
            TransferBed::class => TransferBedHandler::class,
            CorrectOccupancy::class => CorrectOccupancyHandler::class,
            RequestBedReservation::class => RequestBedReservationHandler::class,
            HoldBedReservation::class => HoldBedReservationHandler::class,
            CancelBedReservation::class => CancelBedReservationHandler::class,
            ExpireBedReservation::class => ExpireBedReservationHandler::class,
        ]);

        QueryBus::map([
            GetWard::class => GetWardHandler::class,
            GetBed::class => GetBedHandler::class,
            GetWardCensus::class => GetWardCensusHandler::class,
            GetAvailableBeds::class => GetAvailableBedsHandler::class,
            GetEncounterBed::class => GetEncounterBedHandler::class,
            GetBedOccupancyHistory::class => GetBedOccupancyHistoryHandler::class,
            GetFacilityOccupancy::class => GetFacilityOccupancyHandler::class,
        ]);

        Gate::policy(Ward::class, WardPolicy::class);
        Gate::policy(Bed::class, BedPolicy::class);
        Gate::policy(BedOccupancy::class, BedOccupancyPolicy::class);
        Gate::policy(BedReservation::class, BedReservationPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireLapsedBedReservationsCommand::class,
            ]);
        }
    }
}
