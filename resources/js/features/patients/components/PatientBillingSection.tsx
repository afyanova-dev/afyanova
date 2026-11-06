import { Tag } from '@carbon/react';
import type { ReactNode } from 'react';
import { formatClinicalDateTime } from '@/lib/formatting';
import type { PatientChartBilling } from '@/features/patients/types/chart';
import { clinicalTagType, humanizeClinicalValue } from './patientChartData';
import { PatientRecordTable, PatientSectionState, type PatientSectionAccessState } from './PatientRecordTable';

export function PatientBillingSection({
  billing,
  state,
}: {
  billing: PatientChartBilling;
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  const account = billing.account;
  const outstanding = billing.outstanding;

  return (
    <div className="patient-chart__billing">
      <dl className="patient-chart__billing-summary" aria-label="Billing account summary">
        <div>
          <dt>Account</dt>
          <dd>{account?.accountNumber ?? 'No account'}</dd>
        </div>
        <div>
          <dt>Account status</dt>
          <dd>{account ? <Tag type={clinicalTagType(account.status)}>{humanizeClinicalValue(account.status)}</Tag> : '—'}</dd>
        </div>
        <div>
          <dt>Outstanding</dt>
          <dd>{outstanding ? `${outstanding.outstanding} ${outstanding.currency}` : '—'}</dd>
        </div>
        <div>
          <dt>Settled</dt>
          <dd>{outstanding ? `${outstanding.settledTotal} ${outstanding.currency}` : '—'}</dd>
        </div>
        <div>
          <dt>Ledger entries</dt>
          <dd>{outstanding?.entryCount ?? billing.ledger.length}</dd>
        </div>
      </dl>

      <PatientRecordTable
        title="Billing ledger"
        ariaLabel="Patient billing ledger"
        searchLabel="Search billing ledger"
        headers={[
          { key: 'posted', header: 'Posted' },
          { key: 'type', header: 'Entry type' },
          { key: 'direction', header: 'Direction' },
          { key: 'amount', header: 'Amount' },
          { key: 'currency', header: 'Currency' },
        ]}
        rows={billing.ledger.map((entry, index) => ({
          id: `${entry.postedAt}-${entry.entryType}-${index}`,
          cells: [
            formatClinicalDateTime(entry.postedAt) ?? entry.postedAt,
            humanizeClinicalValue(entry.entryType),
            entry.direction,
            entry.amount,
            entry.currency,
          ],
        }))}
        statusHeaders={['direction']}
        emptyTitle="No billing history"
        emptyDescription="No charges, payments, or adjustments are recorded for this patient."
      />
    </div>
  );
}
