import type { ReactNode } from 'react';
import { PageHeader } from '@/components/ui';
import {
  ProcedureQueueRoutingAction,
  ProcedureProtocolGovernanceAction,
  QueueBoard,
  QueueRoomRoutingAction,
  QueueRoomStaffRoutingAction,
  type QueueBoardProps,
} from '@/features/service-queues';
import { AppLayout } from '@/layouts/AppLayout';

export default function ServiceQueuesIndex(props: QueueBoardProps): ReactNode {
  return (
    <AppLayout>
      <PageHeader
        title="Service Queues"
        description="Facility queue overview and patient flow."
        actions={(
          <>
            <QueueRoomRoutingAction
              rooms={props.rooms}
              queueRoomAssignments={props.queueRoomAssignments}
            />
            <QueueRoomStaffRoutingAction
              queueRoomAssignments={props.queueRoomAssignments}
              queueRoomStaffAssignments={props.queueRoomStaffAssignments ?? []}
              facilityStaff={props.facilityStaff ?? []}
              rooms={props.rooms}
            />
            <ProcedureQueueRoutingAction routing={props.procedureRouting ?? null} />
            <ProcedureProtocolGovernanceAction governance={props.procedureProtocolGovernance ?? null} />
          </>
        )}
      />
      <QueueBoard {...props} />
    </AppLayout>
  );
}
