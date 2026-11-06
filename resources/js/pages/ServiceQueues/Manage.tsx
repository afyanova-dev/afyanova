import { Tab, TabPanel, TabPanels, Tabs, TableToolbarSearch, Tag } from '@carbon/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/ui';
import { CarbonDataTable, ContainedTabList, EmptyState } from '@/components/ui';
import {
  ProcedureQueueRoutingAction,
  ProcedureProtocolGovernanceAction,
  QueueDefinitionAction,
  QueueRoomRoutingAction,
  QueueRoomStaffRoutingAction,
  type ServiceQueueManagementProps,
} from '@/features/service-queues';
import { AppLayout } from '@/layouts/AppLayout';

const QUEUE_HEADERS = [
  { key: 'queue', header: 'Queue' },
  { key: 'code', header: 'Code' },
  { key: 'service', header: 'Service' },
  { key: 'department', header: 'Department' },
  { key: 'ticketPrefix', header: 'Ticket prefix' },
  { key: 'status', header: 'Status' },
  { key: 'actions', header: 'Actions' },
];

const ROUTING_HEADERS = [
  { key: 'queue', header: 'Queue' },
  { key: 'room', header: 'Service location' },
  { key: 'staff', header: 'Staff access' },
];

const PROCEDURE_HEADERS = [
  { key: 'procedure', header: 'Procedure' },
  { key: 'code', header: 'Code' },
  { key: 'destination', header: 'Automatic destination' },
];

export default function ServiceQueueManagement(props: ServiceQueueManagementProps): ReactNode {
  const departmentNames = new Map(props.queueDepartments.map((department) => [department.id, department.name]));
  const roomNames = new Map(props.rooms.map((room) => [room.id, room.name]));
  const staffNames = new Map(props.facilityStaff.map((staff) => [staff.userId, staff.name]));
  const procedureQueueNames = new Map(
    props.procedureRouting?.queues.map((queue) => [queue.queueId, `${queue.queue} (${queue.code})`]) ?? [],
  );
  const roomStaffAssignments = new Map(
    props.queueRoomStaffAssignments.map((assignment) => [
      `${assignment.queueId}:${assignment.roomId}`,
      assignment.userIds,
    ]),
  );
  const queueRows = props.queueDefinitions.map((queue) => ({
    id: queue.id,
    queue: queue.name,
    code: queue.code,
    service: queue.serviceCapability?.replaceAll('_', ' ') ?? 'Not specified',
    department: queue.departmentId ? departmentNames.get(queue.departmentId) ?? 'Unavailable' : 'Not assigned',
    ticketPrefix: queue.ticketPrefix,
    status: queue.isActive ? 'Available' : 'Inactive',
  }));
  const routingRows = props.queueRoomAssignments.flatMap((assignment) => {
    if (assignment.roomIds.length === 0) {
      return [{
        id: `${assignment.queueId}:unassigned`,
        queue: `${assignment.queue} (${assignment.code})`,
        room: 'No specific location',
        staff: 'Available to staff with queue permissions',
      }];
    }

    return assignment.roomIds.map((roomId) => {
      const staff = roomStaffAssignments.get(`${assignment.queueId}:${roomId}`) ?? [];
      const staffNamesForRoom = staff
        .map((staffId) => staffNames.get(staffId))
        .filter((name): name is string => Boolean(name));

      return {
        id: `${assignment.queueId}:${roomId}`,
        queue: `${assignment.queue} (${assignment.code})`,
        room: roomNames.get(roomId) ?? 'Location unavailable',
        staff: staffNamesForRoom.length > 0
          ? staffNamesForRoom.join(', ')
          : staff.length > 0
            ? 'Assigned staff unavailable'
            : 'Available to staff with queue permissions',
      };
    });
  });
  const procedureRows = props.procedureRouting?.definitions.map((definition) => ({
    id: definition.definitionId,
    procedure: definition.name,
    code: definition.code,
    destination: procedureQueueNames.get(definition.queueId) ?? 'Not routed automatically',
  })) ?? [];
  return (
    <AppLayout>
      <PageHeader
        title="Service Queues"
        description="Review and configure the queues and routing used by Patient Flow."
      />
      <main className="service-queues__management">
        <Tabs>
          <ContainedTabList aria-label="Queue configuration areas">
            <Tab>Queue definitions ({queueRows.length})</Tab>
            <Tab>Service locations &amp; staff access ({routingRows.length})</Tab>
            <Tab>Procedure routing ({procedureRows.length})</Tab>
          </ContainedTabList>
          <TabPanels>
            <TabPanel>
              <CarbonDataTable
                rows={queueRows}
                headers={QUEUE_HEADERS}
                title="Facility queues"
                ariaLabel="Facility queue definitions"
                toolbarLabel="Queue definitions table toolbar"
                emptyState={(
                  <EmptyState
                    title="No queues are configured"
                    description="Create a queue to define the services available in this facility."
                  />
                )}
                toolbar={(onInputChange) => (
                  <>
                    <TableToolbarSearch
                      persistent
                      onChange={onInputChange}
                      placeholder="Search queues"
                      aria-label="Search queue definitions"
                    />
                    <QueueDefinitionAction
                      queues={props.queueDefinitions}
                      departments={props.queueDepartments}
                      serviceCapabilities={props.serviceCapabilities}
                      rooms={props.rooms}
                      roomAssignments={props.queueRoomAssignments}
                      action={{ label: 'Create queue', size: 'lg' }}
                    />
                  </>
                )}
                renderCell={(cell, row) => {
                  if (cell.info.header === 'status') {
                    return <Tag type={cell.value === 'Available' ? 'green' : 'gray'}>{cell.value}</Tag>;
                  }
                  if (cell.info.header === 'actions') {
                    return (
                      <QueueDefinitionAction
                        queues={props.queueDefinitions}
                        departments={props.queueDepartments}
                        serviceCapabilities={props.serviceCapabilities}
                        rooms={props.rooms}
                        roomAssignments={props.queueRoomAssignments}
                        action={{ label: 'Edit', queueId: row.id }}
                      />
                    );
                  }

                  return cell.value;
                }}
              />
            </TabPanel>
            <TabPanel>
              <CarbonDataTable
                rows={routingRows}
                headers={ROUTING_HEADERS}
                title="Service locations and staff access"
                ariaLabel="Queue service locations and staff access"
                toolbarLabel="Service locations and staff access table toolbar"
                emptyState={(
                  <EmptyState
                    title="No queues are configured"
                    description="Create a queue first. Service locations and staff restrictions are optional."
                  />
                )}
                toolbar={(onInputChange) => (
                  <>
                    <TableToolbarSearch
                      persistent
                      onChange={onInputChange}
                      placeholder="Search routing"
                      aria-label="Search service locations and staff access"
                    />
                    <QueueRoomRoutingAction
                      rooms={props.rooms}
                      queueRoomAssignments={props.queueRoomAssignments}
                      buttonSize="lg"
                    />
                    <QueueRoomStaffRoutingAction
                      queueRoomAssignments={props.queueRoomAssignments}
                      queueRoomStaffAssignments={props.queueRoomStaffAssignments}
                      facilityStaff={props.facilityStaff}
                      rooms={props.rooms}
                      buttonSize="lg"
                    />
                  </>
                )}
              />
            </TabPanel>
            <TabPanel>
              {props.procedureRouting === null ? (
                <EmptyState
                  title="Procedure routing is unavailable"
                  description="You need procedure-read access to view and configure procedure destinations."
                />
              ) : (
                <>
                  <CarbonDataTable
                    rows={procedureRows}
                    headers={PROCEDURE_HEADERS}
                    title="Procedure destinations"
                    ariaLabel="Procedure queue routing"
                    toolbarLabel="Procedure routing table toolbar"
                    emptyState={(
                      <EmptyState
                        title="No procedure definitions are available"
                        description="Procedure definitions will appear here when available to this facility."
                      />
                    )}
                    toolbar={(onInputChange) => (
                      <>
                        <TableToolbarSearch
                          persistent
                          onChange={onInputChange}
                          placeholder="Search procedures"
                          aria-label="Search procedure routing"
                        />
                        <ProcedureQueueRoutingAction
                          routing={props.procedureRouting}
                          buttonSize="lg"
                        />
                      </>
                    )}
                  />
                  {props.procedureProtocolGovernance ? (
                    <div className="service-queues__management-governance">
                      <ProcedureProtocolGovernanceAction governance={props.procedureProtocolGovernance} />
                    </div>
                  ) : null}
                </>
              )}
            </TabPanel>
          </TabPanels>
        </Tabs>
      </main>
    </AppLayout>
  );
}
