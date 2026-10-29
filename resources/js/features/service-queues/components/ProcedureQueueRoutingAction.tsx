import { Button, InlineNotification, Modal, Select, SelectItem } from '@carbon/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import { routes } from '@/app/routes';
import { permissions, useCan } from '@/lib/authorization';
import type { ProcedureQueueRouting } from '../types/queue-board';

export function ProcedureQueueRoutingAction({
  routing,
}: {
  routing: ProcedureQueueRouting | null;
}): ReactNode {
  const can = useCan();
  const canConfigure = can(permissions.queue.definitionManage)
    && can(permissions.clinical.proceduresRead);
  const [open, setOpen] = useState(false);
  const [mappings, setMappings] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  if (!canConfigure || routing === null || routing === undefined) return null;
  const config = routing;

  function openDialog(): void {
    setMappings(Object.fromEntries(
      config.definitions.map((definition) => [definition.definitionId, definition.queueId]),
    ));
    setError(null);
    setOpen(true);
  }

  function save(): void {
    setError(null);
    setSaving(true);
    router.put(
      routes.workspaces.procedureQueueRouting,
      {
        mappings: config.definitions.map((definition) => ({
          definition_id: definition.definitionId,
          queue_id: mappings[definition.definitionId] ?? '',
        })),
      },
      {
        preserveScroll: true,
        onError: (errors) => {
          const firstError = Object.values(errors)[0];
          setError(firstError ?? 'Procedure queue routing could not be saved.');
        },
        onSuccess: () => setOpen(false),
        onFinish: () => setSaving(false),
      },
    );
  }

  return (
    <>
      <Button kind="secondary" size="sm" onClick={openDialog}>
        Configure procedure queues
      </Button>
      <Modal
        open={open}
        modalHeading="Configure procedure queues"
        primaryButtonText="Save routing"
        secondaryButtonText="Cancel"
        primaryButtonDisabled={saving}
        onRequestClose={() => setOpen(false)}
        onRequestSubmit={save}
      >
        <p>
          Authorized procedures are automatically sent to the selected service queue.
          Leave a procedure unassigned to prevent it from being routed automatically.
        </p>
        {config.definitions.length === 0 ? (
          <p>No active procedure definitions are available.</p>
        ) : null}
        {config.queues.length === 0 ? (
          <InlineNotification
            kind="warning"
            lowContrast
            title="No active procedure queues"
            subtitle="Create or activate a queue with the Procedure service capability before configuring automatic routing."
          />
        ) : null}
        {config.definitions.map((definition) => (
          <Select
            key={definition.definitionId}
            id={`procedure-queue-${definition.definitionId}`}
            labelText={`${definition.name} (${definition.code})`}
            value={mappings[definition.definitionId] ?? ''}
            onChange={(event) => {
              setMappings((current) => ({
                ...current,
                [definition.definitionId]: event.currentTarget.value,
              }));
            }}
            style={{ marginBlockStart: '1rem' }}
          >
            <SelectItem value="" text="Do not route automatically" />
            {config.queues.map((queue) => (
              <SelectItem
                key={queue.queueId}
                value={queue.queueId}
                text={`${queue.queue} (${queue.code})`}
              />
            ))}
          </Select>
        ))}
        {error ? (
          <div role="alert" style={{ marginBlockStart: '1rem' }}>
            <InlineNotification kind="error" lowContrast title={error} />
          </div>
        ) : null}
      </Modal>
    </>
  );
}
