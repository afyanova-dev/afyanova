import type { ReactNode } from 'react';
import { FrontDeskBoard } from '@/features/front-desk/components/FrontDeskBoard';
import type { ReceptionPageProps } from '@/features/front-desk/types/reception';
import { AppLayout } from '@/layouts/AppLayout';

/**
 * Front Desk workspace (Today primary; roster status filters live in the table toolbar).
 *
 * Composes the verified `Reception/Index` snapshot. Summary cards are
 * informational; roster filters are applied explicitly from the table toolbar.
 * Unscheduled arrivals go through the header quick search plus register action.
 */
export default function ReceptionIndex({
  date,
  timezone,
  encounters,
  appointments,
  metrics,
  availableQueues,
  protocolProcedures = [],
  preselectedPatient,
  activeEncounter,
  rosterStatus,
  rosterFilter,
  weekLabel,
  days,
  restricted,
}: ReceptionPageProps): ReactNode {
  return (
    <AppLayout>
      <FrontDeskBoard
        date={date}
        timezone={timezone}
        encounters={encounters}
        appointments={appointments}
        metrics={metrics}
        availableQueues={availableQueues}
        protocolProcedures={protocolProcedures}
        preselectedPatient={preselectedPatient}
        activeEncounter={activeEncounter}
        rosterStatus={rosterStatus}
        rosterFilter={rosterFilter}
        weekLabel={weekLabel}
        days={days}
        restricted={restricted}
      />
    </AppLayout>
  );
}
