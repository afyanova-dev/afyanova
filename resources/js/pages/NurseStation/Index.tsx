import { Link } from '@inertiajs/react';
import { Button, Tag } from '@carbon/react';
import type { ReactNode } from 'react';
import { EmptyState, PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout';
import type { NurseStationEntry } from '@/features/nurse-station/types';
import { routes } from '@/app/routes';
import { ArrowRight } from '@/icons';

interface NurseStationIndexProps {
  entries: NurseStationEntry[];
}

function formatWait(minutes: number): string {
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  const remaining = minutes % 60;
  return remaining === 0 ? `${hours} hr` : `${hours} hr ${remaining} min`;
}

function statusLabel(status: string): string {
  return status.replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export default function NurseStationIndex({ entries }: NurseStationIndexProps): ReactNode {
  return (
    <AppLayout>
      <PageHeader
        title="Nurse Station"
        description="Patients currently routed to a triage-capable queue."
      />
      {entries.length === 0 ? (
        <EmptyState
          title="No patients are waiting for nursing"
          description="Open triage queue tickets will appear here when reception routes patients to nursing."
        />
      ) : (
        <div style={{ display: 'grid', gap: '0.75rem' }}>
          {entries.map((entry) => (
            <article
              key={entry.id}
              style={{
                padding: '1rem',
                display: 'grid',
                gridTemplateColumns: 'minmax(0, 1fr) auto',
                alignItems: 'center',
                gap: '1rem',
              }}
            >
              <div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem', alignItems: 'center' }}>
                  <h2 style={{ margin: 0 }}>{entry.patientName}</h2>
                  <Tag type={entry.priority === 'EMERGENCY' ? 'red' : entry.priority === 'URGENT' ? 'purple' : 'gray'}>
                    {entry.priority}
                  </Tag>
                  <Tag type="blue">{statusLabel(entry.status)}</Tag>
                </div>
                <p style={{ margin: '0.4rem 0 0' }}>
                  {entry.ticketNumber} · {entry.patientNumber} · {entry.queue} · Waiting {formatWait(entry.waitMinutes)}
                </p>
              </div>
              <Button as={Link} href={routes.workspaces.nurseStationEntry(entry.id)} size="lg" renderIcon={ArrowRight}>
                {entry.status === 'WAITING' ? 'Review queue ticket' : 'Open assessment'}
              </Button>
            </article>
          ))}
        </div>
      )}
    </AppLayout>
  );
}