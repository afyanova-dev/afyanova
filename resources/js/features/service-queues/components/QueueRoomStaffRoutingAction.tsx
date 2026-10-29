import { Button, FilterableMultiSelect, InlineNotification, Modal, Select, SelectItem } from '@carbon/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import { routes } from '@/app/routes';
import { permissions, useCan } from '@/lib/authorization';
import type {
  QueueBoardProps,
  QueueRoomStaffAssignment,
  QueueRoomStaffMember,
} from '../types/queue-board';

export function QueueRoomStaffRoutingAction({
  queueRoomAssignments,
  queueRoomStaffAssignments,
  facilityStaff,
  rooms,
}: {
  queueRoomAssignments: QueueBoardProps['queueRoomAssignments'];
  queueRoomStaffAssignments: QueueRoomStaffAssignment[];
  facilityStaff: QueueRoomStaffMember[];
  rooms: QueueBoardProps['rooms'];
}): ReactNode {
  const can = useCan();
  const canManageRouting = can(permissions.queue.definitionManage);
  const [queueId, setQueueId] = useState('');
  const [roomId, setRoomId] = useState('');
  const [staffIds, setStaffIds] = useState<string[]>([]);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const selectedQueue = queueRoomAssignments.find((item) => item.queueId === queueId);
  const selectedRoom = selectedQueue?.roomIds.includes(roomId) ?? false;
  const availableRooms = selectedQueue
    ? rooms.filter((room) => selectedQueue.roomIds.includes(room.id))
    : [];

  if (!canManageRouting || queueRoomAssignments.length === 0) return null;

  function openDialog(): void {
    const firstQueue = queueRoomAssignments[0];
    if (!firstQueue) return;
    const firstRoomId = firstQueue.roomIds[0] ?? '';
    setQueueId(firstQueue.queueId);
    setRoomId(firstRoomId);
    setStaffIds(queueRoomStaffAssignments.find(
      (item) => item.queueId === firstQueue.queueId && item.roomId === firstRoomId,
    )?.userIds ?? []);
    setError(null);
    setOpen(true);
  }

  function save(): void {
    if (!selectedQueue || !selectedRoom || roomId === '') return;
    setError(null);
    router.put(
      routes.workspaces.queueRoomStaff(queueId, roomId),
      { staff_ids: staffIds },
      {
        preserveScroll: true,
        onError: (errors) => setError(errors.staff_ids ?? 'Staff assignments could not be saved.'),
        onSuccess: () => setOpen(false),
      },
    );
  }

  return (
    <>
      <Button kind="secondary" size="sm" onClick={openDialog}>
        Assign queue staff
      </Button>
      <Modal
        open={open}
        modalHeading="Assign staff to queue rooms"
        primaryButtonText="Save assignments"
        secondaryButtonText="Cancel"
        primaryButtonDisabled={!selectedQueue || !selectedRoom}
        onRequestClose={() => setOpen(false)}
        onRequestSubmit={save}
      >
        <Select
          id="queue-staff-queue"
          labelText="Queue"
          value={queueId}
          onChange={(event) => {
            const nextQueueId = event.currentTarget.value;
            const nextQueue = queueRoomAssignments.find((item) => item.queueId === nextQueueId);
            const nextRoomId = nextQueue?.roomIds[0] ?? '';
            setQueueId(nextQueueId);
            setRoomId(nextRoomId);
            setStaffIds(queueRoomStaffAssignments.find(
              (item) => item.queueId === nextQueueId && item.roomId === nextRoomId,
            )?.userIds ?? []);
          }}
        >
          {queueRoomAssignments.map((queue) => (
            <SelectItem key={queue.queueId} value={queue.queueId} text={`${queue.queue} (${queue.code})`} />
          ))}
        </Select>
        <Select
          id="queue-staff-room"
          labelText="Room"
          value={roomId}
          disabled={!selectedQueue || selectedQueue.roomIds.length === 0}
          onChange={(event) => {
            const nextRoomId = event.currentTarget.value;
            setRoomId(nextRoomId);
            setStaffIds(queueRoomStaffAssignments.find(
              (item) => item.queueId === queueId && item.roomId === nextRoomId,
            )?.userIds ?? []);
          }}
        >
          {availableRooms.map((room) => {
            return <SelectItem key={room.id} value={room.id} text={`${room.name} (${room.code})`} />;
          })}
        </Select>
        {facilityStaff.length > 0 ? (
          <FilterableMultiSelect
            id="queue-staff-members"
            titleText="Staff assigned to this queue room"
            placeholder="Search staff by name"
            items={facilityStaff.map((staff) => ({ id: staff.userId, text: staff.name }))}
            itemToString={(item) => item?.text ?? ''}
            selectedItems={facilityStaff
              .filter((staff) => staffIds.includes(staff.userId))
              .map((staff) => ({ id: staff.userId, text: staff.name }))}
            onChange={({ selectedItems }) => setStaffIds(selectedItems.map((item) => item.id))}
            disabled={!selectedRoom}
          />
        ) : (
          <InlineNotification
            kind="warning"
            lowContrast
            title="No active staff accounts are available"
            subtitle="Create or activate staff identities for this facility before assigning queue access."
          />
        )}
        <p>Only staff assigned here can call patients to this room or start and complete their service.</p>
        {error ? (
          <div role="alert" className="service-queues__action-error">
            <InlineNotification kind="error" title={error} lowContrast />
          </div>
        ) : null}
      </Modal>
    </>
  );
}
