import { Button, InlineNotification, Select, SelectItem, TextInput } from '@carbon/react';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { routes } from '@/app/routes';
import { permissions, useCan } from '@/lib/authorization';
import type { ProcedureProtocolGovernance } from '../types/queue-board';
import { Checkmark, TrashCan } from '@/icons';

export function ProcedureProtocolGovernanceAction({
  governance,
}: {
  governance: ProcedureProtocolGovernance | null;
}): ReactNode {
  const can = useCan();
  const canManage = can(permissions.clinical.proceduresProtocolManage)
    && can(permissions.clinical.proceduresRead);
  const [definitionId, setDefinitionId] = useState('');
  const [protocolReference, setProtocolReference] = useState('');
  const [protocolVersion, setProtocolVersion] = useState('');
  const [revocationReasons, setRevocationReasons] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  if (!canManage || governance === null || governance === undefined) return null;

  const approvalByDefinition = new Map(
    governance.approvals.map((approval) => [approval.definitionId, approval]),
  );
  const availableDefinitions = governance.definitions.filter(
    (definition) => !approvalByDefinition.has(definition.definitionId),
  );

  function approve(): void {
    setError(null);
    setSaving(true);
    router.post(
      routes.workspaces.procedureProtocolApprove,
      {
        definition_id: definitionId,
        protocol_reference: protocolReference,
        protocol_version: protocolVersion,
      },
      {
        preserveScroll: true,
        onError: (errors) => setError(Object.values(errors)[0] ?? 'Protocol approval could not be saved.'),
        onFinish: () => setSaving(false),
      },
    );
  }

  function revoke(approvalId: string): void {
    setError(null);
    setSaving(true);
    router.post(
      routes.workspaces.procedureProtocolRevoke(approvalId),
      { reason: revocationReasons[approvalId] ?? '' },
      {
        preserveScroll: true,
        onError: (errors) => setError(Object.values(errors)[0] ?? 'Protocol approval could not be revoked.'),
        onFinish: () => setSaving(false),
      },
    );
  }

  return (
    <section
      aria-label="Direct-service protocol approvals"
      style={{ display: 'grid', gap: '1rem', maxWidth: '44rem', marginBlock: '1rem' }}
    >
      <h2 style={{ marginBlockEnd: 0 }}>Direct-service protocol approvals</h2>
      <InlineNotification
        kind="warning"
        lowContrast
        hideCloseButton
        title="Clinical governance required"
        subtitle="Record an externally approved protocol reference and version. This system does not determine clinical eligibility; do not approve a service without clinical-governance authorization."
      />

      {availableDefinitions.length > 0 ? (
        <div style={{ display: 'grid', gap: '0.75rem' }}>
          <Select
            id="direct-protocol-definition"
            labelText="Procedure"
            value={definitionId}
            onChange={(event) => setDefinitionId(event.currentTarget.value)}
          >
            <SelectItem value="" text="Select a procedure" />
            {availableDefinitions.map((definition) => (
              <SelectItem
                key={definition.definitionId}
                value={definition.definitionId}
                text={`${definition.name} (${definition.code})`}
              />
            ))}
          </Select>
          <TextInput
            id="direct-protocol-reference"
            labelText="Approved protocol reference"
            value={protocolReference}
            onChange={(event) => setProtocolReference(event.currentTarget.value)}
            placeholder="Document or policy identifier"
          />
          <TextInput
            id="direct-protocol-version"
            labelText="Protocol version"
            value={protocolVersion}
            onChange={(event) => setProtocolVersion(event.currentTarget.value)}
            placeholder="For example, 1.0"
          />
          <Button size="lg" renderIcon={Checkmark}
            disabled={saving || definitionId === '' || protocolReference.trim() === '' || protocolVersion.trim() === ''}
            onClick={approve}
          >
            {saving ? 'Saving…' : 'Record protocol approval'}
          </Button>
        </div>
      ) : (
        <p>Every active procedure definition already has an approval, or no active definitions are available.</p>
      )}

      {governance.approvals.map((approval) => {
        const definition = governance.definitions.find((item) => item.definitionId === approval.definitionId);
        const current = definition?.version === approval.definitionVersion;
        return (
          <div
            key={approval.approvalId}
            style={{ padding: '1rem' }}
          >
            <h3 style={{ marginBlockStart: 0 }}>{definition?.name ?? 'Procedure'} · {current ? 'Current' : 'Needs review'}</h3>
            <p>
              {approval.protocolReference} · version {approval.protocolVersion}
              {' · catalog version '}{approval.definitionVersion}
            </p>
            {!current ? (
              <InlineNotification
                kind="warning"
                lowContrast
                hideCloseButton
                subtitle="The procedure definition changed after approval. Direct Reception routing is disabled until this approval is replaced."
              />
            ) : null}
            <TextInput
              id={`protocol-revoke-reason-${approval.approvalId}`}
              labelText="Reason for revocation"
              value={revocationReasons[approval.approvalId] ?? ''}
              onChange={(event) => setRevocationReasons((currentReasons) => ({
                ...currentReasons,
                [approval.approvalId]: event.currentTarget.value,
              }))}
            />
            <Button size="lg" renderIcon={TrashCan}
              kind="danger"
              disabled={saving || (revocationReasons[approval.approvalId] ?? '').trim() === ''}
              onClick={() => revoke(approval.approvalId)}
              style={{ marginTop: '0.75rem' }}
            >
              Revoke approval
            </Button>
          </div>
        );
      })}

      {error ? (
        <div role="alert">
          <InlineNotification kind="error" lowContrast title={error} />
        </div>
      ) : null}
    </section>
  );
}