import { router } from '@inertiajs/react';
import { Button, Tag } from '@carbon/react';
import { useState, type ReactNode } from 'react';
import { AppLayout, Breadcrumbs } from '@/layouts/AppLayout';
import { EmptyState, PageHeader } from '@/components/ui';
import { ConfirmDialog } from '@/components/feedback';
import { routes } from '@/app/routes';
import { Laptop } from '@/icons';

interface DeviceSession {
  id: string;
  ip: string | null;
  userAgent: string | null;
  lastActiveAt: string | null;
  current: boolean;
}

export default function Sessions({ sessions }: { sessions: DeviceSession[] }): ReactNode {
  const [pendingId, setPendingId] = useState<string | null>(null);

  return (
    <AppLayout>
      <div className="afyanova-page">
        <Breadcrumbs items={[{ label: 'Settings' }, { label: 'My sessions' }]} />
        <PageHeader
          title="My sessions"
          description="Devices currently signed in to your account. Revoking a session signs that device out."
        />
        <div className="afy-page__content">
          {sessions.length === 0 ? (
            <EmptyState title="No active sessions" description="There are no other devices signed in." />
          ) : (
            <section className="afy-section" aria-labelledby="active-sessions-title">
              <div className="afy-section__heading">
                <h2 id="active-sessions-title" className="afy-section__title">Active sessions</h2>
                <Tag type="gray">
                  {sessions.length} {sessions.length === 1 ? 'session' : 'sessions'}
                </Tag>
              </div>
              <ul className="afy-rows afy-session-list" aria-label="Active device sessions">
                {sessions.map((session) => (
                  <li key={session.id}>
                    <div className="afy-session-list__identity">
                      <span className="afy-icon" aria-hidden="true">
                        <Laptop />
                      </span>
                      <div className="afy-rows__main">
                        <span className="afy-session-list__title-row">
                          <strong className="afy-card__title">
                            {session.ip ?? 'Unknown address'}
                          </strong>
                          {session.current ? <Tag type="blue">This device</Tag> : null}
                        </span>
                        <span className="afy-session-list__agent">
                          {session.userAgent ?? 'Unknown device'}
                        </span>
                        {session.lastActiveAt ? (
                          <span className="afy-session-list__meta">
                            Last active: {session.lastActiveAt}
                          </span>
                        ) : null}
                      </div>
                    </div>
                    {session.current ? null : (
                      <Button
                        className="afy-session-list__action"
                        kind="danger--ghost"
                        size="sm"
                        type="button"
                        onClick={() => setPendingId(session.id)}
                      >
                        Revoke
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      </div>
      <ConfirmDialog
        open={pendingId !== null}
        heading="Revoke session?"
        body="This device will be signed out immediately."
        confirmLabel="Revoke session"
        danger
        onClose={() => setPendingId(null)}
        onConfirm={() => {
          if (pendingId) router.delete(routes.sessions.destroy(pendingId));
          setPendingId(null);
        }}
      />
    </AppLayout>
  );
}
