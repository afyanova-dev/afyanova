<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class ReceptionMetrics
{
    public function __construct(
        public int $totalEncounters,
        public int $waitingEncounters,
        public int $inServiceEncounters,
        public ?int $inServicePatients,
        public int $activeAttendanceEncounters,
        public int $completedEncounters,
        public int $totalAppointments,
        public int $expectedAppointments,
        public int $arrivedAppointments,
        public int $inServiceAppointments,
        public int $completedAppointments,
    ) {}
}
