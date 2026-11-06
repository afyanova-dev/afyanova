import { Button, InlineNotification, TableToolbarSearch, Tag } from '@carbon/react';
import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { ConfirmDialog } from '@/components/feedback';
import {
  CarbonDataTable,
  EmptyState,
  FormErrors,
  PageHeader,
  StatisticsCards,
} from '@/components/ui';
import type { CatalogEditorCandidate } from '@/features/laboratory/types';
import { UserFollow, UserMultiple, UserSettings } from '@/icons';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface LaboratoryCatalogEditorsProps {
  candidates: CatalogEditorCandidate[];
  errors?: Record<string, string>;
}

const CANDIDATE_HEADERS = [
  { key: 'technician', header: 'Technician' },
  { key: 'role', header: 'Facility role' },
  { key: 'setupAccess', header: 'Test setup access' },
  { key: 'actions', header: 'Actions' },
];

export default function LaboratoryCatalogEditors({
  candidates,
  errors = {},
}: LaboratoryCatalogEditorsProps): ReactNode {
  const [revokeTarget, setRevokeTarget] = useState<CatalogEditorCandidate | null>(null);
  const [processingUserId, setProcessingUserId] = useState<string | null>(null);

  const editors = candidates.filter((candidate) => candidate.isEditor);
  const eligibleForGrant = candidates.filter((candidate) => !candidate.isEditor);

  function grant(candidate: CatalogEditorCandidate): void {
    setProcessingUserId(candidate.userId);
    router.post(
      `${routes.workspaces.adminLaboratoryCatalogEditors}/${encodeURIComponent(candidate.userId)}`,
      {},
      {
        preserveScroll: true,
        onFinish: () => setProcessingUserId(null),
      },
    );
  }

  function revoke(): void {
    if (revokeTarget === null || processingUserId !== null) return;

    setProcessingUserId(revokeTarget.userId);
    router.delete(
      `${routes.workspaces.adminLaboratoryCatalogEditors}/${encodeURIComponent(revokeTarget.userId)}`,
      {
        preserveScroll: true,
        onFinish: () => {
          setProcessingUserId(null);
          setRevokeTarget(null);
        },
      },
    );
  }

  const rows = candidates.map((candidate) => ({
    id: candidate.userId,
    technician: `${candidate.name} (${candidate.email})`,
    role: 'Laboratory technician',
    setupAccess: candidate.isEditor ? 'Granted' : 'Not granted',
    actions: '',
  }));

  return (
    <AppLayout>
      <main className="afyanova-page laboratory-page laboratory-catalog-editors">
        <PageHeader
          title="Laboratory test setup access"
          description="Allow selected active laboratory technicians to configure the tests and panels available to clinicians."
          actions={(
            <Button as={Link} href={routes.workspaces.laboratoryCatalog} kind="ghost" size="lg">
              Back to tests & panels
            </Button>
          )}
        />

        <div className="afy-page__content">
          <FormErrors errors={errors} fields={[]} />

          <StatisticsCards
            label="Laboratory catalog editor access summary"
            cards={[
              {
                id: 'lab-editors-eligible',
                label: 'Eligible technicians',
                value: candidates.length,
                detail: 'Active laboratory staff',
                icon: <UserMultiple />,
                borderTone: 'information',
              },
              {
                id: 'lab-editors-granted',
                label: 'With setup access',
                value: editors.length,
                detail: 'Can manage tests & panels',
                icon: <UserSettings />,
                borderTone: 'positive',
              },
              {
                id: 'lab-editors-available',
                label: 'Available to grant',
                value: eligibleForGrant.length,
                detail: 'Technician role only',
                icon: <UserFollow />,
              },
            ]}
          />

          <InlineNotification
            kind="info"
            lowContrast
            hideCloseButton
            title="Who can be selected"
            subtitle="The technician must have an active staff identity linked to their account and an effective Laboratory Technician assignment at a facility in this tenant."
          />

          <section aria-label="Laboratory catalog editor candidates">
            <CarbonDataTable
              rows={rows}
              headers={CANDIDATE_HEADERS}
              title={(
                <span className="laboratory-table__title">
                  Technician access
                  <span>{candidates.length} eligible</span>
                </span>
              )}
              ariaLabel="Laboratory test setup access"
              toolbarLabel="Laboratory test setup access toolbar"
              toolbar={(onInputChange) => (
                <TableToolbarSearch
                  persistent
                  onChange={onInputChange}
                  placeholder="Search technicians"
                  aria-label="Search laboratory technicians"
                />
              )}
              emptyState={(
                <EmptyState
                  title={candidates.length === 0
                    ? 'No eligible laboratory technicians'
                    : 'No technicians match your search'}
                  description={candidates.length === 0
                    ? 'To grant access, first add an active staff identity linked to a user account and assign that person a Laboratory Technician role at a facility in this tenant. Return here after those are in place.'
                    : 'Try a different search term.'}
                />
              )}
              renderCell={(cell, row) => {
                const candidate = candidates.find((item) => item.userId === row.id);
                if (!candidate) return cell.value;

                if (cell.info.header === 'technician') {
                  return (
                    <div className="laboratory-table__primary">
                      <strong>{candidate.name}</strong>
                      <span>{candidate.email}</span>
                    </div>
                  );
                }
                if (cell.info.header === 'setupAccess') {
                  return (
                    <Tag type={candidate.isEditor ? 'green' : 'gray'}>
                      {candidate.isEditor ? 'Granted' : 'Not granted'}
                    </Tag>
                  );
                }
                if (cell.info.header === 'actions') {
                  if (candidate.isEditor) {
                    return (
                      <Button
                        kind="danger--tertiary"
                        size="sm"
                        aria-label={`Revoke test setup access for ${candidate.name}`}
                        disabled={processingUserId === candidate.userId}
                        onClick={() => setRevokeTarget(candidate)}
                      >
                        Revoke access
                      </Button>
                    );
                  }

                  return (
                    <Button
                      kind="primary"
                      size="sm"
                      aria-label={`Grant test setup access to ${candidate.name}`}
                      disabled={processingUserId === candidate.userId}
                      onClick={() => grant(candidate)}
                    >
                      {processingUserId === candidate.userId ? 'Granting…' : 'Grant access'}
                    </Button>
                  );
                }

                return cell.value;
              }}
            />
          </section>
        </div>
      </main>

      <ConfirmDialog
        open={revokeTarget !== null}
        heading="Revoke test setup access?"
        body={revokeTarget
          ? `Revoke test setup access for ${revokeTarget.name}? They will no longer be able to create or edit laboratory tests and panels. Their laboratory technician role is unchanged.`
          : ''}
        confirmLabel={processingUserId !== null ? 'Revoking…' : 'Revoke access'}
        primaryButtonDisabled={processingUserId !== null}
        danger
        onClose={() => {
          if (processingUserId === null) setRevokeTarget(null);
        }}
        onConfirm={revoke}
      />
    </AppLayout>
  );
}
