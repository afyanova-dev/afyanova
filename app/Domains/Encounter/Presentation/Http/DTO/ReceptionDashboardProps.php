<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class ReceptionDashboardProps
{
    /**
     * @param  list<ReceptionEncounterItem>  $encounters
     * @param  list<ReceptionAppointmentItem>  $appointments
     * @param  list<array<string, mixed>>  $availableQueues
     * @param  list<array<string, mixed>>|null  $searchResults
     * @param  list<ReceptionReferralItem>  $referrals
     * @param  list<string>  $restricted
     */
    public function __construct(
        public string $facilityId,
        public string $date,
        public string $timezone,
        #[LiteralTypeScriptType('Array<App.Domains.Encounter.Presentation.Http.DTO.ReceptionEncounterItem>')]
        public array $encounters,
        #[LiteralTypeScriptType('Array<App.Domains.Encounter.Presentation.Http.DTO.ReceptionAppointmentItem>')]
        public array $appointments,
        public ReceptionMetrics $metrics,
        public array $availableQueues = [],
        public ?array $searchResults = null,
        #[LiteralTypeScriptType('Array<App.Domains.Encounter.Presentation.Http.DTO.ReceptionReferralItem>')]
        public array $referrals = [],
        public string $rosterStatus = 'ALL',
        public string $rosterFilter = '',
        public ?string $searchError = null,
        public ?string $weekLabel = null,
        public ?array $days = null,
        public array $restricted = [],
    ) {}
}
