<?php

declare(strict_types=1);

namespace App\Domains\HR;

use App\Domains\HR\Application\Command\AddProfessionalRegistration;
use App\Domains\HR\Application\Command\AddProfessionalRegistrationHandler;
use App\Domains\HR\Application\Command\AmendAssignment;
use App\Domains\HR\Application\Command\AmendAssignmentHandler;
use App\Domains\HR\Application\Command\AmendEmployment;
use App\Domains\HR\Application\Command\AmendEmploymentHandler;
use App\Domains\HR\Application\Command\ApproveLeave;
use App\Domains\HR\Application\Command\ApproveLeaveHandler;
use App\Domains\HR\Application\Command\AssignStaff;
use App\Domains\HR\Application\Command\AssignStaffHandler;
use App\Domains\HR\Application\Command\CancelLeave;
use App\Domains\HR\Application\Command\CancelLeaveHandler;
use App\Domains\HR\Application\Command\CancelShift;
use App\Domains\HR\Application\Command\CancelShiftHandler;
use App\Domains\HR\Application\Command\CloseAttendanceSession;
use App\Domains\HR\Application\Command\CloseAttendanceSessionHandler;
use App\Domains\HR\Application\Command\ConfirmLeaveTaken;
use App\Domains\HR\Application\Command\ConfirmLeaveTakenHandler;
use App\Domains\HR\Application\Command\CreateEmployment;
use App\Domains\HR\Application\Command\CreateEmploymentHandler;
use App\Domains\HR\Application\Command\CreatePerson;
use App\Domains\HR\Application\Command\CreatePersonHandler;
use App\Domains\HR\Application\Command\CreateStaff;
use App\Domains\HR\Application\Command\CreateStaffHandler;
use App\Domains\HR\Application\Command\DefineCadre;
use App\Domains\HR\Application\Command\DefineCadreHandler;
use App\Domains\HR\Application\Command\DefineLeaveType;
use App\Domains\HR\Application\Command\DefineLeaveTypeHandler;
use App\Domains\HR\Application\Command\DefinePosition;
use App\Domains\HR\Application\Command\DefinePositionHandler;
use App\Domains\HR\Application\Command\DefineShiftPattern;
use App\Domains\HR\Application\Command\DefineShiftPatternHandler;
use App\Domains\HR\Application\Command\EndAssignment;
use App\Domains\HR\Application\Command\EndAssignmentHandler;
use App\Domains\HR\Application\Command\EndEmployment;
use App\Domains\HR\Application\Command\EndEmploymentHandler;
use App\Domains\HR\Application\Command\IngestAttendanceEvent;
use App\Domains\HR\Application\Command\IngestAttendanceEventHandler;
use App\Domains\HR\Application\Command\LinkStaffToUser;
use App\Domains\HR\Application\Command\LinkStaffToUserHandler;
use App\Domains\HR\Application\Command\OnboardStaff;
use App\Domains\HR\Application\Command\OnboardStaffHandler;
use App\Domains\HR\Application\Command\PostLeaveLedgerEntry;
use App\Domains\HR\Application\Command\PostLeaveLedgerEntryHandler;
use App\Domains\HR\Application\Command\PublishRoster;
use App\Domains\HR\Application\Command\PublishRosterHandler;
use App\Domains\HR\Application\Command\RaiseAttendanceException;
use App\Domains\HR\Application\Command\RaiseAttendanceExceptionHandler;
use App\Domains\HR\Application\Command\RebuildAttendanceSessions;
use App\Domains\HR\Application\Command\RebuildAttendanceSessionsHandler;
use App\Domains\HR\Application\Command\ReconcileAttendanceSession;
use App\Domains\HR\Application\Command\ReconcileAttendanceSessionHandler;
use App\Domains\HR\Application\Command\RecordRegistrationVerification;
use App\Domains\HR\Application\Command\RecordRegistrationVerificationHandler;
use App\Domains\HR\Application\Command\ReinstateEmployment;
use App\Domains\HR\Application\Command\ReinstateEmploymentHandler;
use App\Domains\HR\Application\Command\ReinstateStaff;
use App\Domains\HR\Application\Command\ReinstateStaffHandler;
use App\Domains\HR\Application\Command\RejectLeave;
use App\Domains\HR\Application\Command\RejectLeaveHandler;
use App\Domains\HR\Application\Command\RequestLeave;
use App\Domains\HR\Application\Command\RequestLeaveHandler;
use App\Domains\HR\Application\Command\ScheduleRosterEntry;
use App\Domains\HR\Application\Command\ScheduleRosterEntryHandler;
use App\Domains\HR\Application\Command\StartEmployment;
use App\Domains\HR\Application\Command\StartEmploymentHandler;
use App\Domains\HR\Application\Command\SuspendEmployment;
use App\Domains\HR\Application\Command\SuspendEmploymentHandler;
use App\Domains\HR\Application\Command\SuspendStaff;
use App\Domains\HR\Application\Command\SuspendStaffHandler;
use App\Domains\HR\Application\Command\SwapShift;
use App\Domains\HR\Application\Command\SwapShiftHandler;
use App\Domains\HR\Application\Command\TerminateStaff;
use App\Domains\HR\Application\Command\TerminateStaffHandler;
use App\Domains\HR\Application\Command\UnlinkStaffFromUser;
use App\Domains\HR\Application\Command\UnlinkStaffFromUserHandler;
use App\Domains\HR\Application\Command\UpdateCadre;
use App\Domains\HR\Application\Command\UpdateCadreHandler;
use App\Domains\HR\Application\Command\UpdatePerson;
use App\Domains\HR\Application\Command\UpdatePersonHandler;
use App\Domains\HR\Application\Command\UpdatePosition;
use App\Domains\HR\Application\Command\UpdatePositionHandler;
use App\Domains\HR\Application\Command\UpdateRegistrationStatus;
use App\Domains\HR\Application\Command\UpdateRegistrationStatusHandler;
use App\Domains\HR\Application\Command\UpdateShiftPattern;
use App\Domains\HR\Application\Command\UpdateShiftPatternHandler;
use App\Domains\HR\Application\Query\GetActiveAssignments;
use App\Domains\HR\Application\Query\GetActiveAssignmentsHandler;
use App\Domains\HR\Application\Query\GetAttendanceEvents;
use App\Domains\HR\Application\Query\GetAttendanceEventsHandler;
use App\Domains\HR\Application\Query\GetAttendanceExceptions;
use App\Domains\HR\Application\Query\GetAttendanceExceptionsHandler;
use App\Domains\HR\Application\Query\GetAttendanceSessions;
use App\Domains\HR\Application\Query\GetAttendanceSessionsHandler;
use App\Domains\HR\Application\Query\GetCadre;
use App\Domains\HR\Application\Query\GetCadreHandler;
use App\Domains\HR\Application\Query\GetCadres;
use App\Domains\HR\Application\Query\GetCadresHandler;
use App\Domains\HR\Application\Query\GetEmployment;
use App\Domains\HR\Application\Query\GetEmploymentHandler;
use App\Domains\HR\Application\Query\GetLeaveBalance;
use App\Domains\HR\Application\Query\GetLeaveBalanceHandler;
use App\Domains\HR\Application\Query\GetLeaveCalendar;
use App\Domains\HR\Application\Query\GetLeaveCalendarHandler;
use App\Domains\HR\Application\Query\GetLeaveLedger;
use App\Domains\HR\Application\Query\GetLeaveLedgerHandler;
use App\Domains\HR\Application\Query\GetLeaveRequests;
use App\Domains\HR\Application\Query\GetLeaveRequestsHandler;
use App\Domains\HR\Application\Query\GetLeaveTypes;
use App\Domains\HR\Application\Query\GetLeaveTypesHandler;
use App\Domains\HR\Application\Query\GetOnShiftNow;
use App\Domains\HR\Application\Query\GetOnShiftNowHandler;
use App\Domains\HR\Application\Query\GetPerson;
use App\Domains\HR\Application\Query\GetPersonHandler;
use App\Domains\HR\Application\Query\GetPosition;
use App\Domains\HR\Application\Query\GetPositionHandler;
use App\Domains\HR\Application\Query\GetPositions;
use App\Domains\HR\Application\Query\GetPositionsHandler;
use App\Domains\HR\Application\Query\GetRegistrations;
use App\Domains\HR\Application\Query\GetRegistrationsHandler;
use App\Domains\HR\Application\Query\GetRoster;
use App\Domains\HR\Application\Query\GetRosterHandler;
use App\Domains\HR\Application\Query\GetShiftPattern;
use App\Domains\HR\Application\Query\GetShiftPatternHandler;
use App\Domains\HR\Application\Query\GetShiftPatterns;
use App\Domains\HR\Application\Query\GetShiftPatternsHandler;
use App\Domains\HR\Application\Query\GetStaff;
use App\Domains\HR\Application\Query\GetStaffAssignments;
use App\Domains\HR\Application\Query\GetStaffAssignmentsHandler;
use App\Domains\HR\Application\Query\GetStaffByUser;
use App\Domains\HR\Application\Query\GetStaffByUserHandler;
use App\Domains\HR\Application\Query\GetStaffDetail;
use App\Domains\HR\Application\Query\GetStaffDetailHandler;
use App\Domains\HR\Application\Query\GetStaffEmployments;
use App\Domains\HR\Application\Query\GetStaffEmploymentsHandler;
use App\Domains\HR\Application\Query\GetStaffHandler;
use App\Domains\HR\Application\Query\GetStaffLeaveBalances;
use App\Domains\HR\Application\Query\GetStaffLeaveBalancesHandler;
use App\Domains\HR\Application\Query\GetStaffRoster;
use App\Domains\HR\Application\Query\GetStaffRosterHandler;
use App\Domains\HR\Application\Query\GetTimesheet;
use App\Domains\HR\Application\Query\GetTimesheetHandler;
use App\Domains\HR\Application\Query\GetUserByStaff;
use App\Domains\HR\Application\Query\GetUserByStaffHandler;
use App\Domains\HR\Application\Query\GetWorkforceAvailability;
use App\Domains\HR\Application\Query\GetWorkforceAvailabilityHandler;
use App\Domains\HR\Application\Query\SearchStaff;
use App\Domains\HR\Application\Query\SearchStaffHandler;
use App\Domains\HR\Authorization\AssignmentPolicy;
use App\Domains\HR\Authorization\AttendancePolicy;
use App\Domains\HR\Authorization\CadrePolicy;
use App\Domains\HR\Authorization\EmploymentPolicy;
use App\Domains\HR\Authorization\LeavePolicy;
use App\Domains\HR\Authorization\PersonPolicy;
use App\Domains\HR\Authorization\PositionPolicy;
use App\Domains\HR\Authorization\ProfessionalRegistrationPolicy;
use App\Domains\HR\Authorization\RosterEntryPolicy;
use App\Domains\HR\Authorization\ShiftPatternPolicy;
use App\Domains\HR\Authorization\StaffPolicy;
use App\Domains\HR\Authorization\StaffUserLinkPolicy;
use App\Domains\HR\Contracts\HRApi;
use App\Domains\HR\Contracts\HRApiService;
use App\Domains\HR\Domain\AssignmentReader;
use App\Domains\HR\Domain\AssignmentRepository;
use App\Domains\HR\Domain\AttendanceEventReader;
use App\Domains\HR\Domain\AttendanceEventRepository;
use App\Domains\HR\Domain\AttendanceSessionReader;
use App\Domains\HR\Domain\AttendanceSessionRepository;
use App\Domains\HR\Domain\CadrePositionReader;
use App\Domains\HR\Domain\CadreRepository;
use App\Domains\HR\Domain\EmploymentReader;
use App\Domains\HR\Domain\EmploymentRepository;
use App\Domains\HR\Domain\LeaveLedgerEntryRepository;
use App\Domains\HR\Domain\LeaveLedgerReader;
use App\Domains\HR\Domain\LeaveRequestReader;
use App\Domains\HR\Domain\LeaveRequestRepository;
use App\Domains\HR\Domain\LeaveTypeReader;
use App\Domains\HR\Domain\LeaveTypeRepository;
use App\Domains\HR\Domain\Model\Assignment;
use App\Domains\HR\Domain\Model\AttendanceEvent;
use App\Domains\HR\Domain\Model\AttendanceSession;
use App\Domains\HR\Domain\Model\Cadre;
use App\Domains\HR\Domain\Model\Employment;
use App\Domains\HR\Domain\Model\LeaveLedgerEntry;
use App\Domains\HR\Domain\Model\LeaveRequest;
use App\Domains\HR\Domain\Model\LeaveType;
use App\Domains\HR\Domain\Model\Person;
use App\Domains\HR\Domain\Model\Position;
use App\Domains\HR\Domain\Model\ProfessionalRegistration;
use App\Domains\HR\Domain\Model\RosterEntry;
use App\Domains\HR\Domain\Model\ShiftPattern;
use App\Domains\HR\Domain\Model\Staff;
use App\Domains\HR\Domain\Model\StaffUserLink;
use App\Domains\HR\Domain\PersonReader;
use App\Domains\HR\Domain\PersonRepository;
use App\Domains\HR\Domain\PositionRepository;
use App\Domains\HR\Domain\ProfessionalRegistrationReader;
use App\Domains\HR\Domain\ProfessionalRegistrationRepository;
use App\Domains\HR\Domain\RosterEntryReader;
use App\Domains\HR\Domain\RosterEntryRepository;
use App\Domains\HR\Domain\ShiftPatternReader;
use App\Domains\HR\Domain\ShiftPatternRepository;
use App\Domains\HR\Domain\StaffReader;
use App\Domains\HR\Domain\StaffRepository;
use App\Domains\HR\Domain\StaffUserLinkReader;
use App\Domains\HR\Domain\StaffUserLinkRepository;
use App\Domains\HR\Domain\WorkforceAvailabilityReader;
use App\Domains\HR\Infrastructure\Reader\EloquentAssignmentReader;
use App\Domains\HR\Infrastructure\Reader\EloquentAttendanceEventReader;
use App\Domains\HR\Infrastructure\Reader\EloquentAttendanceSessionReader;
use App\Domains\HR\Infrastructure\Reader\EloquentCadrePositionReader;
use App\Domains\HR\Infrastructure\Reader\EloquentEmploymentReader;
use App\Domains\HR\Infrastructure\Reader\EloquentLeaveLedgerReader;
use App\Domains\HR\Infrastructure\Reader\EloquentLeaveRequestReader;
use App\Domains\HR\Infrastructure\Reader\EloquentLeaveTypeReader;
use App\Domains\HR\Infrastructure\Reader\EloquentPersonReader;
use App\Domains\HR\Infrastructure\Reader\EloquentProfessionalRegistrationReader;
use App\Domains\HR\Infrastructure\Reader\EloquentRosterEntryReader;
use App\Domains\HR\Infrastructure\Reader\EloquentShiftPatternReader;
use App\Domains\HR\Infrastructure\Reader\EloquentStaffReader;
use App\Domains\HR\Infrastructure\Reader\EloquentStaffUserLinkReader;
use App\Domains\HR\Infrastructure\Reader\EloquentWorkforceAvailabilityReader;
use App\Domains\HR\Infrastructure\Repository\EloquentAssignmentRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentAttendanceEventRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentAttendanceSessionRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentCadreRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentEmploymentRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentLeaveLedgerEntryRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentLeaveRequestRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentLeaveTypeRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentPersonRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentPositionRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentProfessionalRegistrationRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentRosterEntryRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentShiftPatternRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentStaffRepository;
use App\Domains\HR\Infrastructure\Repository\EloquentStaffUserLinkRepository;
use App\Domains\HR\Infrastructure\Sequence\StaffNumberSequence;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for Domain 25 (HR & Workforce) (ADR-206).
 */
final class HRServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Repositories
        $this->app->bind(PersonRepository::class, EloquentPersonRepository::class);
        $this->app->bind(StaffRepository::class, EloquentStaffRepository::class);
        $this->app->bind(EmploymentRepository::class, EloquentEmploymentRepository::class);
        $this->app->bind(CadreRepository::class, EloquentCadreRepository::class);
        $this->app->bind(PositionRepository::class, EloquentPositionRepository::class);
        $this->app->bind(AssignmentRepository::class, EloquentAssignmentRepository::class);
        $this->app->bind(ProfessionalRegistrationRepository::class, EloquentProfessionalRegistrationRepository::class);
        $this->app->bind(StaffUserLinkRepository::class, EloquentStaffUserLinkRepository::class);
        $this->app->bind(ShiftPatternRepository::class, EloquentShiftPatternRepository::class);
        $this->app->bind(RosterEntryRepository::class, EloquentRosterEntryRepository::class);
        $this->app->bind(AttendanceEventRepository::class, EloquentAttendanceEventRepository::class);
        $this->app->bind(AttendanceSessionRepository::class, EloquentAttendanceSessionRepository::class);
        $this->app->bind(LeaveTypeRepository::class, EloquentLeaveTypeRepository::class);
        $this->app->bind(LeaveLedgerEntryRepository::class, EloquentLeaveLedgerEntryRepository::class);
        $this->app->bind(LeaveRequestRepository::class, EloquentLeaveRequestRepository::class);

        // Readers
        $this->app->bind(PersonReader::class, EloquentPersonReader::class);
        $this->app->bind(StaffReader::class, EloquentStaffReader::class);
        $this->app->bind(EmploymentReader::class, EloquentEmploymentReader::class);
        $this->app->bind(CadrePositionReader::class, EloquentCadrePositionReader::class);
        $this->app->bind(AssignmentReader::class, EloquentAssignmentReader::class);
        $this->app->bind(ProfessionalRegistrationReader::class, EloquentProfessionalRegistrationReader::class);
        $this->app->bind(StaffUserLinkReader::class, EloquentStaffUserLinkReader::class);
        $this->app->bind(ShiftPatternReader::class, EloquentShiftPatternReader::class);
        $this->app->bind(RosterEntryReader::class, EloquentRosterEntryReader::class);
        $this->app->bind(AttendanceEventReader::class, EloquentAttendanceEventReader::class);
        $this->app->bind(AttendanceSessionReader::class, EloquentAttendanceSessionReader::class);
        $this->app->bind(LeaveTypeReader::class, EloquentLeaveTypeReader::class);
        $this->app->bind(LeaveLedgerReader::class, EloquentLeaveLedgerReader::class);
        $this->app->bind(LeaveRequestReader::class, EloquentLeaveRequestReader::class);
        $this->app->bind(WorkforceAvailabilityReader::class, EloquentWorkforceAvailabilityReader::class);

        // Sequence
        $this->app->singleton(StaffNumberSequence::class);

        // Contracts / API
        $this->app->bind(HRApi::class, HRApiService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            // Person
            CreatePerson::class => CreatePersonHandler::class,
            UpdatePerson::class => UpdatePersonHandler::class,

            // Cadre & Position
            DefineCadre::class => DefineCadreHandler::class,
            UpdateCadre::class => UpdateCadreHandler::class,
            DefinePosition::class => DefinePositionHandler::class,
            UpdatePosition::class => UpdatePositionHandler::class,

            // Staff
            CreateStaff::class => CreateStaffHandler::class,
            OnboardStaff::class => OnboardStaffHandler::class,
            SuspendStaff::class => SuspendStaffHandler::class,
            ReinstateStaff::class => ReinstateStaffHandler::class,
            TerminateStaff::class => TerminateStaffHandler::class,

            // Employment
            CreateEmployment::class => CreateEmploymentHandler::class,
            StartEmployment::class => StartEmploymentHandler::class,
            SuspendEmployment::class => SuspendEmploymentHandler::class,
            ReinstateEmployment::class => ReinstateEmploymentHandler::class,
            AmendEmployment::class => AmendEmploymentHandler::class,
            EndEmployment::class => EndEmploymentHandler::class,

            // Assignment
            AssignStaff::class => AssignStaffHandler::class,
            AmendAssignment::class => AmendAssignmentHandler::class,
            EndAssignment::class => EndAssignmentHandler::class,

            // Professional Registration
            AddProfessionalRegistration::class => AddProfessionalRegistrationHandler::class,
            UpdateRegistrationStatus::class => UpdateRegistrationStatusHandler::class,
            RecordRegistrationVerification::class => RecordRegistrationVerificationHandler::class,

            // Staff-User Link
            LinkStaffToUser::class => LinkStaffToUserHandler::class,
            UnlinkStaffFromUser::class => UnlinkStaffFromUserHandler::class,

            // Rostering & Shifts
            DefineShiftPattern::class => DefineShiftPatternHandler::class,
            UpdateShiftPattern::class => UpdateShiftPatternHandler::class,
            ScheduleRosterEntry::class => ScheduleRosterEntryHandler::class,
            PublishRoster::class => PublishRosterHandler::class,
            SwapShift::class => SwapShiftHandler::class,
            CancelShift::class => CancelShiftHandler::class,

            // Attendance & Sessions
            IngestAttendanceEvent::class => IngestAttendanceEventHandler::class,
            RebuildAttendanceSessions::class => RebuildAttendanceSessionsHandler::class,
            CloseAttendanceSession::class => CloseAttendanceSessionHandler::class,
            ReconcileAttendanceSession::class => ReconcileAttendanceSessionHandler::class,
            RaiseAttendanceException::class => RaiseAttendanceExceptionHandler::class,

            // Leave Management
            DefineLeaveType::class => DefineLeaveTypeHandler::class,
            PostLeaveLedgerEntry::class => PostLeaveLedgerEntryHandler::class,
            RequestLeave::class => RequestLeaveHandler::class,
            ApproveLeave::class => ApproveLeaveHandler::class,
            RejectLeave::class => RejectLeaveHandler::class,
            CancelLeave::class => CancelLeaveHandler::class,
            ConfirmLeaveTaken::class => ConfirmLeaveTakenHandler::class,
        ]);

        QueryBus::map([
            // Person
            GetPerson::class => GetPersonHandler::class,

            // Staff
            GetStaff::class => GetStaffHandler::class,
            GetStaffDetail::class => GetStaffDetailHandler::class,
            SearchStaff::class => SearchStaffHandler::class,

            // Employment
            GetEmployment::class => GetEmploymentHandler::class,
            GetStaffEmployments::class => GetStaffEmploymentsHandler::class,

            // Cadre & Position
            GetCadre::class => GetCadreHandler::class,
            GetCadres::class => GetCadresHandler::class,
            GetPosition::class => GetPositionHandler::class,
            GetPositions::class => GetPositionsHandler::class,

            // Assignment
            GetActiveAssignments::class => GetActiveAssignmentsHandler::class,
            GetStaffAssignments::class => GetStaffAssignmentsHandler::class,

            // Professional Registration
            GetRegistrations::class => GetRegistrationsHandler::class,

            // Staff-User Link
            GetStaffByUser::class => GetStaffByUserHandler::class,
            GetUserByStaff::class => GetUserByStaffHandler::class,

            // Rostering & Shifts
            GetShiftPattern::class => GetShiftPatternHandler::class,
            GetShiftPatterns::class => GetShiftPatternsHandler::class,
            GetRoster::class => GetRosterHandler::class,
            GetStaffRoster::class => GetStaffRosterHandler::class,
            GetOnShiftNow::class => GetOnShiftNowHandler::class,

            // Attendance & Sessions
            GetAttendanceEvents::class => GetAttendanceEventsHandler::class,
            GetAttendanceSessions::class => GetAttendanceSessionsHandler::class,
            GetAttendanceExceptions::class => GetAttendanceExceptionsHandler::class,
            GetTimesheet::class => GetTimesheetHandler::class,

            // Leave Management & Workforce Availability
            GetLeaveTypes::class => GetLeaveTypesHandler::class,
            GetLeaveLedger::class => GetLeaveLedgerHandler::class,
            GetLeaveBalance::class => GetLeaveBalanceHandler::class,
            GetStaffLeaveBalances::class => GetStaffLeaveBalancesHandler::class,
            GetLeaveRequests::class => GetLeaveRequestsHandler::class,
            GetLeaveCalendar::class => GetLeaveCalendarHandler::class,
            GetWorkforceAvailability::class => GetWorkforceAvailabilityHandler::class,
        ]);

        Gate::policy(Person::class, PersonPolicy::class);
        Gate::policy(Staff::class, StaffPolicy::class);
        Gate::policy(Employment::class, EmploymentPolicy::class);
        Gate::policy(Cadre::class, CadrePolicy::class);
        Gate::policy(Position::class, PositionPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(ProfessionalRegistration::class, ProfessionalRegistrationPolicy::class);
        Gate::policy(StaffUserLink::class, StaffUserLinkPolicy::class);
        Gate::policy(ShiftPattern::class, ShiftPatternPolicy::class);
        Gate::policy(RosterEntry::class, RosterEntryPolicy::class);
        Gate::policy(AttendanceEvent::class, AttendancePolicy::class);
        Gate::policy(AttendanceSession::class, AttendancePolicy::class);
        Gate::policy(LeaveType::class, LeavePolicy::class);
        Gate::policy(LeaveLedgerEntry::class, LeavePolicy::class);
        Gate::policy(LeaveRequest::class, LeavePolicy::class);
    }
}
