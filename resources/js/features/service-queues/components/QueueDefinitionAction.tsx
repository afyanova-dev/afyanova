import { Button, InlineNotification, Search, Select, SelectItem, Tag, TextInput } from '@carbon/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { createPortal } from 'react-dom';
import { router } from '@inertiajs/react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { permissions, useCan } from '@/lib/authorization';
import type {
  QueueBoardDepartment,
  QueueBoardDefinition,
  QueueBoardRoom,
  QueueRoomAssignment,
} from '../types/queue-board';

interface QueueDefinitionForm {
  code: string;
  name: string;
  department_id: string;
  service_capability: string;
  ticket_prefix: string;
  is_active: boolean;
}

const EMPTY_FORM: QueueDefinitionForm = {
  code: '',
  name: '',
  department_id: '',
  service_capability: '',
  ticket_prefix: 'A',
  is_active: true,
};

function formForQueue(queue: QueueBoardDefinition): QueueDefinitionForm {
  return {
    code: queue.code,
    name: queue.name,
    department_id: queue.departmentId ?? '',
    service_capability: queue.serviceCapability ?? '',
    ticket_prefix: queue.ticketPrefix,
    is_active: queue.isActive,
  };
}

export function QueueDefinitionAction({
  queues,
  departments,
  serviceCapabilities,
  rooms,
  roomAssignments,
  action,
}: {
  queues: QueueBoardDefinition[];
  departments: QueueBoardDepartment[];
  serviceCapabilities: string[];
  rooms: QueueBoardRoom[];
  roomAssignments: QueueRoomAssignment[];
  action?: { label: string; queueId?: string; size?: 'sm' | 'lg' };
}): ReactNode {
  const can = useCan();
  const canManage = can(permissions.queue.definitionManage);
  const [panel, setPanel] = useState<'list' | 'editor' | null>(null);
  const [editingQueue, setEditingQueue] = useState<QueueBoardDefinition | null>(null);
  const [form, setForm] = useState<QueueDefinitionForm>(EMPTY_FORM);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [queueSearch, setQueueSearch] = useState('');
  const departmentNames = new Map(departments.map((department) => [department.id, department.name]));
  const roomNames = new Map(rooms.map((room) => [room.id, room.name]));
  const assignmentByQueue = new Map(roomAssignments.map((assignment) => [assignment.queueId, assignment]));
  const normalizedSearch = queueSearch.trim().toLocaleLowerCase();
  const filteredQueues = queues.filter((queue) => {
    const departmentName = queue.departmentId ? departmentNames.get(queue.departmentId) ?? '' : '';
    return `${queue.name} ${queue.code} ${queue.serviceCapability ?? ''} ${departmentName}`
      .toLocaleLowerCase()
      .includes(normalizedSearch);
  });
  const activeQueueCount = queues.filter((queue) => queue.isActive).length;
  const inactiveQueueCount = queues.length - activeQueueCount;

  if (!canManage) return null;

  function startCreate(): void {
    setEditingQueue(null);
    setForm(EMPTY_FORM);
    setError(null);
    setPanel('editor');
  }

  function startEdit(queue: QueueBoardDefinition): void {
    setEditingQueue(queue);
    setForm(formForQueue(queue));
    setError(null);
    setPanel('editor');
  }

  function closeEditor(): void {
    if (saving) return;
    setError(null);
    setPanel(action ? null : 'list');
  }

  function closeList(): void {
    setPanel(null);
  }

  function save(): void {
    setError(null);
    setSaving(true);
    const payload: Record<string, string | boolean> = { ...form };
    const options = {
      preserveScroll: true,
      onError: (errors: Record<string, string>) => {
        setError(errors.code ?? errors.name ?? errors.department_id ?? errors.service_capability
          ?? errors.ticket_prefix ?? errors.is_active ?? 'Queue changes could not be saved.');
      },
      onSuccess: () => {
        setEditingQueue(null);
        setPanel(action ? null : 'list');
      },
      onFinish: () => setSaving(false),
    };

    if (editingQueue) {
      router.put(routes.workspaces.queueDefinition(editingQueue.id), payload, options);
      return;
    }

    router.post(routes.workspaces.queueDefinitions, payload, options);
  }

  function submit(event: FormEvent): void {
    event.preventDefault();
    save();
  }

  const capabilities = serviceCapabilities.map((capability) => ({
    value: capability,
    text: capability.replaceAll('_', ' '),
  }));
  const isFormValid = form.name.trim() !== ''
    && form.ticket_prefix.trim() !== ''
    && (editingQueue !== null || form.code.trim() !== '');

  return (
    <>
      <Button kind={action ? 'primary' : 'secondary'} size={action?.size ?? 'sm'} onClick={() => {
        if (!action) {
          setPanel('list');
          return;
        }

        if (action.queueId) {
          const queueToEdit = queues.find((queue) => queue.id === action.queueId);
          if (queueToEdit) {
            startEdit(queueToEdit);
          }
          return;
        }

        startCreate();
      }}>
        {action?.label ?? 'Manage queues'}
      </Button>
      {panel === 'list' && !action ? createPortal(
        <DockedPanel
          heading="Manage service queues"
          description="Define facility worklists and the services they handle."
          placement="right"
          width="min(42rem, 100%)"
          expandedWidth="min(60rem, 100%)"
          expandable
          onClose={closeList}
        >
          <div className="service-queues__definition-list-heading">
            <p>Manage the facility worklists used to route patients to services.</p>
            <Button kind="tertiary" size="sm" onClick={startCreate}>Create queue</Button>
          </div>
          <section className="service-queues__definition-overview" aria-label="Queue definition totals">
            <div>
              <strong>{queues.length}</strong>
              <span>Total queues</span>
            </div>
            <div>
              <strong>{activeQueueCount}</strong>
              <span>Available</span>
            </div>
            <div>
              <strong>{inactiveQueueCount}</strong>
              <span>Inactive</span>
            </div>
          </section>
          <section className="service-queues__definition-directory" aria-label="Existing queue definitions">
            {queues.length > 0 ? (
              <Search
                id="service-queue-definition-search"
                labelText="Search queue definitions"
                placeholder="Search by name, code, service, or department"
                value={queueSearch}
                onChange={(event) => setQueueSearch(event.currentTarget.value)}
                onClear={() => setQueueSearch('')}
              />
            ) : null}
            {queues.length === 0 ? (
              <div className="service-queues__definition-empty">
                <h3>No queues configured</h3>
                <p>Create the facility’s first queue. Service locations and staff restrictions can be added later if needed.</p>
                <Button kind="tertiary" size="sm" onClick={startCreate}>Create the first queue</Button>
              </div>
            ) : filteredQueues.length === 0 ? (
              <div className="service-queues__definition-empty" role="status">
                <h3>No matching queues</h3>
                <p>Try a different search term or clear the search.</p>
              </div>
            ) : (
              <div className="service-queues__definition-cards">
                {filteredQueues.map((queue) => {
                  const departmentName = queue.departmentId
                    ? departmentNames.get(queue.departmentId) ?? 'Department unavailable'
                    : 'No department';
                  const assignment = assignmentByQueue.get(queue.id);
                  const assignedRooms = assignment?.roomIds
                    .map((roomId) => roomNames.get(roomId))
                    .filter((name): name is string => Boolean(name)) ?? [];

                  return (
                    <article className="service-queues__definition-card" key={queue.id}>
                      <div className="service-queues__definition-card-header">
                        <div className="service-queues__definition-identity">
                          <h3>{queue.name}</h3>
                          <span>{queue.code}</span>
                        </div>
                        <Tag type={queue.isActive ? 'green' : 'gray'}>
                          {queue.isActive ? 'Available' : 'Inactive'}
                        </Tag>
                      </div>
                      <div className="service-queues__definition-details">
                        <div>
                          <span>Service</span>
                          <strong>{queue.serviceCapability?.replaceAll('_', ' ') ?? 'Not specified'}</strong>
                        </div>
                        <div>
                          <span>Department</span>
                          <strong>{departmentName}</strong>
                        </div>
                        <div>
                          <span>Ticket prefix</span>
                          <strong>{queue.ticketPrefix}</strong>
                        </div>
                      </div>
                      <div className="service-queues__definition-routing">
                        <span>{assignedRooms.length ? 'Service locations' : 'Location optional'}</span>
                        <strong>
                          {assignedRooms.length
                            ? assignedRooms.join(', ')
                            : 'Patients can be called within the queue without a specific service location.'}
                        </strong>
                      </div>
                      <div className="service-queues__definition-card-actions">
                        <Button kind="ghost" size="sm" aria-label={`Edit ${queue.name}`} onClick={() => startEdit(queue)}>
                          Edit
                        </Button>
                      </div>
                    </article>
                  );
                })}
              </div>
            )}
          </section>
        </DockedPanel>,
        document.body,
      ) : null}
      {panel === 'editor' ? createPortal(
        <DockedPanel
          heading={editingQueue ? `Edit ${editingQueue.name}` : 'Create service queue'}
          description="Set the queue identity, department, service, and availability."
          placement="right"
          width="min(32rem, 100%)"
          primaryLabel={saving ? 'Saving…' : editingQueue ? 'Save changes' : 'Create queue'}
          primaryDisabled={!isFormValid || saving}
          secondaryLabel="Back to queues"
          onClose={closeEditor}
          onSecondary={closeEditor}
          onSubmit={submit}
        >
          <p>A queue can run without a specific service location. Configure locations only when staff need call destinations.</p>
          <section className="service-queues__definition-form" aria-label={editingQueue ? 'Edit queue definition' : 'New queue definition'}>
            <div className="service-queues__definition-fields">
              <TextInput
                id="queue-definition-code"
                labelText="Queue code"
                value={form.code}
                disabled={editingQueue !== null}
                helperText={editingQueue ? 'Queue codes cannot be changed after creation.' : 'Use a short unique code, such as TRIAGE or OPD_GP.'}
                onChange={(event) => setForm((current) => ({ ...current, code: event.currentTarget.value.toUpperCase() }))}
              />
              <TextInput
                id="queue-definition-name"
                labelText="Queue name"
                value={form.name}
                onChange={(event) => setForm((current) => ({ ...current, name: event.currentTarget.value }))}
              />
              <Select
                id="queue-definition-department"
                labelText="Department (optional)"
                value={form.department_id}
                onChange={(event) => setForm((current) => ({ ...current, department_id: event.currentTarget.value }))}
              >
                <SelectItem value="" text="No department" />
                {departments.map((department) => (
                  <SelectItem
                    key={department.id}
                    value={department.id}
                    text={`${department.name}${department.isActive ? '' : ' · inactive'}`}
                  />
                ))}
              </Select>
              <Select
                id="queue-definition-capability"
                labelText="Service capability (optional)"
                value={form.service_capability}
                onChange={(event) => setForm((current) => ({ ...current, service_capability: event.currentTarget.value }))}
              >
                <SelectItem value="" text="Not specified" />
                {capabilities.map((capability) => (
                  <SelectItem key={capability.value} value={capability.value} text={capability.text} />
                ))}
              </Select>
              <TextInput
                id="queue-definition-ticket-prefix"
                labelText="Ticket prefix"
                value={form.ticket_prefix}
                maxLength={5}
                helperText="Up to five letters or numbers; used to identify tickets from this queue."
                onChange={(event) => setForm((current) => ({ ...current, ticket_prefix: event.currentTarget.value.toUpperCase() }))}
              />
              <Select
                id="queue-definition-status"
                labelText="Availability"
                value={form.is_active ? 'active' : 'inactive'}
                onChange={(event) => setForm((current) => ({ ...current, is_active: event.currentTarget.value === 'active' }))}
              >
                <SelectItem value="active" text="Available for new check-ins" />
                <SelectItem value="inactive" text="Unavailable for new check-ins" />
              </Select>
            </div>
          </section>
          {error ? (
            <div role="alert" className="service-queues__action-error">
              <InlineNotification kind="error" title={error} lowContrast />
            </div>
          ) : null}
        </DockedPanel>,
        document.body,
      ) : null}
    </>
  );
}
