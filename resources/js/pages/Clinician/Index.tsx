import { Link } from '@inertiajs/react';
import { Button, Tag } from '@carbon/react';
import type { ReactNode } from 'react';
import { EmptyState, PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout';
import type { ClinicianEntry } from '@/features/clinician/types';
import { routes } from '@/app/routes';
import { ArrowRight } from '@/icons';

interface ClinicianIndexProps {
  entries: ClinicianEntry[];
}

function waitLabel(minutes: number): string {
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  const remaining = minutes % 60;
  return remaining === 0 ? `${hours} hr` : `${hours} hr ${remaining} min`;
}

export default function ClinicianIndex({ entries }: ClinicianIndexProps): ReactNode {
  return (
    <AppLayout>
      <PageHeader
        title="Clinician Workspace"
        description="Consultation queue and encounter-centred documentation."
      />
      {entries.length === 0 ? (
        <EmptyState
          title="No patients are queued for consultation"
          description="Patients handed off from triage will appear here with the same encounter and assigned queue priority."
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
                  <Tag type="blue">{entry.status.replaceAll('_', ' ')}</Tag>
                </div>
                <p style={{ margin: '0.4rem 0 0' }}>
                  {entry.ticketNumber} · {entry.patientNumber} · {entry.queue} · Waiting {waitLabel(entry.waitMinutes)}
                </p>
              </div>
              <Button size="lg" renderIcon={ArrowRight} as={Link} href={routes.workspaces.clinicianEntry(entry.id)}>
                Open encounter
              </Button>
            </article>
          ))}
        </div>
      )}
    </AppLayout>
  );
}