import { Button, FilterableMultiSelect, InlineNotification, Modal, Select, SelectItem } from '@carbon/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { routes } from '@/app/routes';
import { permissions, useCan } from '@/lib/authorization';
import type { QueueBoardRoom, QueueRoomAssignment } from '../types/queue-board';

export function QueueRoomRoutingAction({
  rooms,
  queueRoomAssignments,
  buttonSize = 'sm',
}: {
  rooms: QueueBoardRoom[];
  queueRoomAssignments: QueueRoomAssignment[];
  buttonSize?: 'sm' | 'lg';
}): ReactNode {
  const can = useCan();
  const canManageRouting = can(permissions.queue.definitionManage);
  const [routingQueueId, setRoutingQueueId] = useState('');
  const [routingRoomIds, setRoutingRoomIds] = useState<string[]>([]);
  const [routingOpen, setRoutingOpen] = useState(false);
  const [routingError, setRoutingError] = useState<string | null>(null);
  const routingQueues = queueRoomAssignments.map((assignment) => ({
    id: assignment.queueId,
    text: `${assignment.queue} (${assignment.code})`,
  }));
  const selectedRoutingQueue = queueRoomAssignments.find((assignment) => assignment.queueId === routingQueueId);

  if (!canManageRouting || queueRoomAssignments.length === 0) return null;

  function openRoutingDialog(): void {
    const firstQueue = queueRoomAssignments[0];
    if (!firstQueue) return;
    setRoutingQueueId(firstQueue.queueId);
    setRoutingRoomIds(firstQueue.roomIds);
    setRoutingError(null);
    setRoutingOpen(true);
  }

  function saveRouting(): void {
    if (!selectedRoutingQueue) return;
    setRoutingError(null);
    router.put(
      routes.workspaces.queueRooms(routingQueueId),
      { room_ids: routingRoomIds },
      {
        preserveScroll: true,
        onError: (errors) => setRoutingError(errors.room_ids ?? 'Room routing could not be saved.'),
        onSuccess: () => setRoutingOpen(false),
      },
    );
  }

  return (
    <>
      <Button kind="secondary" size={buttonSize} onClick={openRoutingDialog}>
        Manage service locations
      </Button>
      {routingOpen ? createPortal(
        <Modal
        open={routingOpen}
        modalHeading="Manage service locations"
        primaryButtonText="Save routing"
        secondaryButtonText="Cancel"
        primaryButtonDisabled={!selectedRoutingQueue}
        onRequestClose={() => setRoutingOpen(false)}
        onRequestSubmit={saveRouting}
      >
        <Select
          id="queue-routing-queue"
          labelText="Queue"
          value={routingQueueId}
          onChange={(event) => {
            const nextQueueId = event.currentTarget.value;
            setRoutingQueueId(nextQueueId);
            setRoutingRoomIds(queueRoomAssignments.find((item) => item.queueId === nextQueueId)?.roomIds ?? []);
          }}
        >
          {routingQueues.map((queue) => (
            <SelectItem key={queue.id} value={queue.id} text={queue.text} />
          ))}
        </Select>
        <FilterableMultiSelect
          id="queue-routing-rooms"
          titleText="Service locations that can serve this queue"
          placeholder="Search locations by name or code"
          items={rooms.map((room) => ({ id: room.id, text: `${room.name} (${room.code})` }))}
          itemToString={(item) => item?.text ?? ''}
          selectedItems={rooms
            .filter((room) => routingRoomIds.includes(room.id))
            .map((room) => ({ id: room.id, text: `${room.name} (${room.code})` }))}
          onChange={({ selectedItems }) => setRoutingRoomIds(selectedItems.map((item) => item.id))}
        />
        <p>Locations are optional. Use any named service area, counter, or room where staff call patients.</p>
        {routingError ? (
          <div role="alert" className="service-queues__action-error">
            <InlineNotification kind="error" title={routingError} lowContrast />
          </div>
        ) : null}
        </Modal>,
        document.body,
      ) : null}
    </>
  );
}
