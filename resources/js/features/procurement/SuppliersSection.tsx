import {
  Button,
  InlineNotification,
  OverflowMenuItem,
  Select,
  SelectItem,
  Stack,
  TableToolbarMenu,
  TableToolbarSearch,
  Tag,
  TextArea,
  TextInput,
} from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { CarbonDataTable, FormErrors, StatisticsCards } from '@/components/ui';
import { Building, Filter, Pending, SettingsAdjust, Time, WarningAlt } from '@/icons';
import { formatStatus, type Supplier } from './types';

export interface SuppliersSectionProps {
  suppliers: Supplier[];
  statuses: string[];
  canManage: boolean;
}

const STATUS_TAG: Record<string, 'green' | 'red' | 'gray'> = {
  ACTIVE: 'green',
  SUSPENDED: 'red',
  INACTIVE: 'gray',
  BLACKLISTED: 'red',
};

const HEADERS = [
  { key: 'code', header: 'Code' },
  { key: 'name', header: 'Supplier' },
  { key: 'contact', header: 'Contact' },
  { key: 'categories', header: 'Categories' },
  { key: 'terms', header: 'Payment terms' },
  { key: 'licence', header: 'Licence' },
  { key: 'status', header: 'Status' },
  { key: 'actions', header: 'Actions' },
];

export function SuppliersSection({ suppliers, statuses, canManage }: SuppliersSectionProps): ReactNode {
  const [statusFilter, setStatusFilter] = useState('');
  const [adding, setAdding] = useState(false);
  const [managing, setManaging] = useState<Supplier | null>(null);

  const addForm = useForm({
    name: '',
    code: '',
    categories: '',
    contact_name: '',
    contact_phone: '',
    contact_email: '',
    registration_number: '',
    licence_number: '',
    licence_authority: '',
    licence_expiry_date: '',
    tax_identifier: '',
    payment_terms: '',
    address: '',
  });

  const statusForm = useForm({
    target_status: '',
    reason: '',
  });

  const licenceForm = useForm({
    licence_number: '',
    licence_authority: '',
    licence_expiry_date: '',
  });

  const filtered = useMemo(
    () => suppliers.filter((supplier) => statusFilter === '' || supplier.status === statusFilter),
    [suppliers, statusFilter],
  );

  const suppliersById = useMemo(
    () => new Map(suppliers.map((supplier) => [supplier.id, supplier])),
    [suppliers],
  );

  const rows = useMemo(
    () => filtered.map((supplier) => ({
      id: supplier.id,
      code: supplier.code,
      name: supplier.name,
      contact: [supplier.contactName, supplier.contactPhone].filter(Boolean).join(' · ') || '—',
      categories: supplier.categories.length > 0 ? supplier.categories.join(', ') : '—',
      terms: supplier.paymentTerms ?? '—',
      licence: supplier.licenceExpiryStatus,
      status: supplier.status,
      actions: '',
    })),
    [filtered],
  );

  const activeCount = suppliers.filter((supplier) => supplier.status === 'ACTIVE').length;
  const suspendedCount = suppliers.filter((supplier) => supplier.status === 'SUSPENDED').length;
  const inactiveCount = suppliers.filter((supplier) => supplier.status === 'INACTIVE').length;
  const blacklistedCount = suppliers.filter((supplier) => supplier.status === 'BLACKLISTED').length;

  function openAdd(): void {
    addForm.clearErrors();
    addForm.reset();
    setAdding(true);
  }

  function closeAdd(): void {
    addForm.reset();
    setAdding(false);
  }

  function submitAdd(event: FormEvent): void {
    event.preventDefault();
    addForm.post(routes.workspaces.procurementSuppliersStore, {
      preserveScroll: true,
      onSuccess: () => {
        addForm.reset();
        setAdding(false);
      },
    });
  }

  function openManage(supplier: Supplier): void {
    statusForm.clearErrors();
    statusForm.setData({
      target_status: supplier.allowedStatuses[0] ?? '',
      reason: '',
    });
    licenceForm.clearErrors();
    licenceForm.setData({
      licence_number: supplier.licenceNumber ?? '',
      licence_authority: supplier.licenceAuthority ?? '',
      licence_expiry_date: supplier.licenceExpiryDate ?? '',
    });
    setManaging(supplier);
  }

  function closeManage(): void {
    statusForm.reset();
    setManaging(null);
  }

  function submitStatus(event: FormEvent): void {
    event.preventDefault();

    if (managing === null) {
      return;
    }

    statusForm.patch(routes.workspaces.procurementSupplierStatus(managing.id), {
      preserveScroll: true,
      onSuccess: () => {
        statusForm.reset();
        setManaging(null);
      },
    });
  }

  function submitLicence(): void {
    if (managing === null) {
      return;
    }

    licenceForm.patch(routes.workspaces.procurementSupplierLicence(managing.id), {
      preserveScroll: true,
    });
  }

  return (
    <section aria-labelledby="procurement-suppliers-heading">
      <div className="afy-section__heading">
        <div>
          <h2 id="procurement-suppliers-heading" className="afy-section__title">Suppliers</h2>
          <p className="afy-section__description">
            The businesses you buy from. Only an active supplier can be put on a purchase order;
            licence expiry reminders do not automatically block orders.
          </p>
        </div>
      </div>

      <StatisticsCards
        label="Supplier summary"
        cards={[
          {
            id: 'supplier-active',
            label: 'Active',
            value: activeCount,
            detail: 'Can be put on purchase orders',
            borderTone: 'positive',
            icon: <Building aria-hidden="true" />,
          },
          {
            id: 'supplier-suspended',
            label: 'Suspended',
            value: suspendedCount,
            detail: 'Temporarily blocked from new orders',
            borderTone: suspendedCount > 0 ? 'caution' : 'information',
            icon: <Pending aria-hidden="true" />,
          },
          {
            id: 'supplier-inactive',
            label: 'Inactive',
            value: inactiveCount,
            detail: 'Archived — takes no new orders',
            borderTone: 'information',
            icon: <Time aria-hidden="true" />,
          },
          {
            id: 'supplier-blacklisted',
            label: 'Blacklisted',
            value: blacklistedCount,
            detail: 'Permanently blocked, cannot be reversed',
            borderTone: blacklistedCount > 0 ? 'negative' : 'information',
            icon: <WarningAlt aria-hidden="true" />,
          },
        ]}
      />

      <CarbonDataTable
        rows={rows}
        headers={HEADERS}
        title={`Suppliers (${filtered.length} of ${suppliers.length})`}
        ariaLabel="Suppliers"
        emptyState={(
          <p>
            {suppliers.length === 0
              ? 'No suppliers registered yet.'
              : 'No supplier matches the selected status.'}
          </p>
        )}
        toolbar={(onInputChange) => (
          <>
            <TableToolbarSearch
              persistent
              onChange={onInputChange}
              placeholder="Search suppliers"
              aria-label="Search suppliers"
            />
            <TableToolbarMenu
              renderIcon={Filter}
              iconDescription={`Filter by status: ${statusFilter === '' ? 'All statuses' : formatStatus(statusFilter)}`}
            >
              <OverflowMenuItem
                itemText={`All statuses (${suppliers.length})`}
                isDelete={false}
                onClick={() => setStatusFilter('')}
              />
              {statuses.map((status) => (
                <OverflowMenuItem
                  key={status}
                  itemText={`${formatStatus(status)} (${suppliers.filter((supplier) => supplier.status === status).length})`}
                  isDelete={false}
                  onClick={() => setStatusFilter(status)}
                />
              ))}
            </TableToolbarMenu>
            {canManage ? (
              <Button size="lg" onClick={openAdd}>
                New supplier
              </Button>
            ) : null}
          </>
        )}
        renderCell={(cell, row) => {
          if (cell.info.header === 'code') {
            return <code className="procurement-code">{String(cell.value)}</code>;
          }

          if (cell.info.header === 'categories') {
            const supplier = suppliersById.get(row.id);
            if (!supplier || supplier.categories.length === 0) {
              return <span className="procurement-muted">None</span>;
            }

            return (
              <div className="procurement-tags">
                {supplier.categories.map((category) => (
                  <Tag key={category} type="outline" size="sm">
                    {category}
                  </Tag>
                ))}
              </div>
            );
          }

          if (cell.info.header === 'licence') {
            const supplier = suppliersById.get(row.id);
            if (!supplier) {
              return null;
            }

            const labels = {
              MISSING: { label: 'Not recorded', type: 'gray' as const },
              EXPIRED: { label: 'Expired', type: 'red' as const },
              EXPIRING_SOON: { label: 'Expires soon', type: 'purple' as const },
              CURRENT: { label: 'Current', type: 'green' as const },
            };
            const expiryStatus = labels[supplier.licenceExpiryStatus];

            return (
              <div className="procurement-tags" aria-label={`Supplier licence: ${expiryStatus.label}`}>
                <Tag type={expiryStatus.type} size="sm">{expiryStatus.label}</Tag>
                {supplier.licenceExpiryDate ? <span>{supplier.licenceExpiryDate}</span> : null}
              </div>
            );
          }

          if (cell.info.header === 'status') {
            const status = String(cell.value);
            return (
              <Tag type={STATUS_TAG[status] ?? 'gray'} size="sm">
                {formatStatus(status)}
              </Tag>
            );
          }

          if (cell.info.header === 'actions') {
            const supplier = suppliersById.get(row.id);
            if (!supplier) {
              return null;
            }

            return canManage ? (
              // The row's one action, outlined so it reads as a button rather
              // than a link, with the settings icon it shares with the panel.
              <Button
                kind="tertiary"
                size="sm"
                renderIcon={SettingsAdjust}
                onClick={() => openManage(supplier)}
              >
                Manage
              </Button>
            ) : null;
          }

          return cell.value;
        }}
      />

      <DockedPanel
        heading="Add supplier"
        hidden={!adding}
        onClose={closeAdd}
        onSubmit={submitAdd}
        primaryLabel={addForm.processing ? 'Adding…' : 'Add supplier'}
        primaryDisabled={addForm.processing || addForm.data.name.trim() === '' || addForm.data.code.trim() === ''}
        width="min(34rem, 100%)"
      >
        <Stack gap={5}>
          <FormErrors errors={addForm.errors} fields={['name', 'code', 'categories']} />

          <TextInput
            id="supplier-name"
            labelText="Supplier name"
            value={addForm.data.name}
            onChange={(event) => addForm.setData('name', event.currentTarget.value)}
            invalid={Boolean(addForm.errors.name)}
            invalidText={addForm.errors.name}
            required
          />

          <TextInput
            id="supplier-code"
            labelText="Code"
            helperText="Unique within your organisation, e.g. PHARMA-01."
            value={addForm.data.code}
            onChange={(event) => addForm.setData('code', event.currentTarget.value)}
            invalid={Boolean(addForm.errors.code)}
            invalidText={addForm.errors.code}
            required
          />

          <TextInput
            id="supplier-categories"
            labelText="Categories"
            helperText="Comma separated, e.g. Pharmaceuticals, Consumables."
            value={addForm.data.categories}
            onChange={(event) => addForm.setData('categories', event.currentTarget.value)}
            invalid={Boolean(addForm.errors.categories)}
            invalidText={addForm.errors.categories}
          />

          <TextInput
            id="supplier-contact-name"
            labelText="Contact person"
            value={addForm.data.contact_name}
            onChange={(event) => addForm.setData('contact_name', event.currentTarget.value)}
            invalid={Boolean(addForm.errors.contact_name)}
            invalidText={addForm.errors.contact_name}
          />

          <div className="procurement-form__pair">
            <TextInput
              id="supplier-contact-phone"
              labelText="Phone"
              value={addForm.data.contact_phone}
              onChange={(event) => addForm.setData('contact_phone', event.currentTarget.value)}
              invalid={Boolean(addForm.errors.contact_phone)}
              invalidText={addForm.errors.contact_phone}
            />

            <TextInput
              id="supplier-contact-email"
              labelText="Email"
              type="email"
              value={addForm.data.contact_email}
              onChange={(event) => addForm.setData('contact_email', event.currentTarget.value)}
              invalid={Boolean(addForm.errors.contact_email)}
              invalidText={addForm.errors.contact_email}
            />
          </div>

          <div className="procurement-form__pair">
            <TextInput
              id="supplier-registration-number"
              labelText="Registration number"
              helperText="Business registration (BRELA) number, if known."
              value={addForm.data.registration_number}
              onChange={(event) => addForm.setData('registration_number', event.currentTarget.value)}
              invalid={Boolean(addForm.errors.registration_number)}
              invalidText={addForm.errors.registration_number}
            />

            <TextInput
              id="supplier-tax-identifier"
              labelText="TIN"
              helperText="Taxpayer Identification Number, if known."
              value={addForm.data.tax_identifier}
              onChange={(event) => addForm.setData('tax_identifier', event.currentTarget.value)}
              invalid={Boolean(addForm.errors.tax_identifier)}
              invalidText={addForm.errors.tax_identifier}
            />
          </div>

          <div className="procurement-form__pair">
            <TextInput
              id="supplier-licence-number"
              labelText="Supplier licence number"
              value={addForm.data.licence_number}
              onChange={(event) => addForm.setData('licence_number', event.currentTarget.value)}
              invalid={Boolean(addForm.errors.licence_number)}
              invalidText={addForm.errors.licence_number}
            />

            <TextInput
              id="supplier-licence-authority"
              labelText="Issuing authority"
              value={addForm.data.licence_authority}
              onChange={(event) => addForm.setData('licence_authority', event.currentTarget.value)}
              invalid={Boolean(addForm.errors.licence_authority)}
              invalidText={addForm.errors.licence_authority}
            />
          </div>

          <TextInput
            id="supplier-licence-expiry-date"
            labelText="Licence expiry date"
            type="date"
            value={addForm.data.licence_expiry_date}
            onChange={(event) => addForm.setData('licence_expiry_date', event.currentTarget.value)}
            invalid={Boolean(addForm.errors.licence_expiry_date)}
            invalidText={addForm.errors.licence_expiry_date}
          />

          <TextInput
            id="supplier-payment-terms"
            labelText="Payment terms"
            helperText="What the supplier expects, e.g. NET 30 or Cash on delivery."
            value={addForm.data.payment_terms}
            onChange={(event) => addForm.setData('payment_terms', event.currentTarget.value)}
            invalid={Boolean(addForm.errors.payment_terms)}
            invalidText={addForm.errors.payment_terms}
          />

          <TextArea
            id="supplier-address"
            labelText="Address"
            rows={3}
            value={addForm.data.address}
            onChange={(event) => addForm.setData('address', event.currentTarget.value)}
            invalid={Boolean(addForm.errors.address)}
            invalidText={addForm.errors.address}
          />
        </Stack>
      </DockedPanel>

      <DockedPanel
        heading={managing ? `Manage ${managing.name}` : 'Manage supplier'}
        ariaLabel="Manage supplier"
        hidden={managing === null}
        onClose={closeManage}
        onSubmit={submitStatus}
        primaryLabel={statusForm.processing ? 'Saving…' : 'Update status'}
        primaryDisabled={
          statusForm.processing
          || statusForm.data.target_status === ''
          || statusForm.data.reason.trim() === ''
        }
        danger={statusForm.data.target_status === 'BLACKLISTED'}
        width="min(32rem, 100%)"
      >
        {managing ? (
          <Stack gap={5}>
            <FormErrors errors={statusForm.errors} fields={['target_status', 'reason']} />
            <FormErrors
              errors={licenceForm.errors}
              fields={['licence_number', 'licence_authority', 'licence_expiry_date', 'supplier']}
            />

            <section aria-label="Supplier licence details">
              <h3>Supplier licence reminder</h3>
              <p>Expiry reminders do not block purchase orders.</p>
              <Stack gap={4}>
                <div className="procurement-form__pair">
                  <TextInput
                    id="manage-supplier-licence-number"
                    labelText="Licence number"
                    value={licenceForm.data.licence_number}
                    onChange={(event) => licenceForm.setData('licence_number', event.currentTarget.value)}
                    invalid={Boolean(licenceForm.errors.licence_number)}
                    invalidText={licenceForm.errors.licence_number}
                  />
                  <TextInput
                    id="manage-supplier-licence-authority"
                    labelText="Issuing authority"
                    value={licenceForm.data.licence_authority}
                    onChange={(event) => licenceForm.setData('licence_authority', event.currentTarget.value)}
                    invalid={Boolean(licenceForm.errors.licence_authority)}
                    invalidText={licenceForm.errors.licence_authority}
                  />
                </div>
                <TextInput
                  id="manage-supplier-licence-expiry-date"
                  labelText="Expiry date"
                  type="date"
                  value={licenceForm.data.licence_expiry_date}
                  onChange={(event) => licenceForm.setData('licence_expiry_date', event.currentTarget.value)}
                  invalid={Boolean(licenceForm.errors.licence_expiry_date)}
                  invalidText={licenceForm.errors.licence_expiry_date}
                />
                <Button
                  type="button"
                  kind="secondary"
                  size="lg"
                  disabled={licenceForm.processing}
                  onClick={submitLicence}
                >
                  {licenceForm.processing ? 'Saving licence…' : 'Save licence details'}
                </Button>
              </Stack>
            </section>

            <dl className="procurement-facts">
              <div>
                <dt>Code</dt>
                <dd><code>{managing.code}</code></dd>
              </div>
              <div>
                <dt>Current status</dt>
                <dd>
                  <Tag type={STATUS_TAG[managing.status] ?? 'gray'} size="sm">
                    {formatStatus(managing.status)}
                  </Tag>
                </dd>
              </div>
              {managing.statusReason ? (
                <div>
                  <dt>Last reason</dt>
                  <dd>{managing.statusReason}</dd>
                </div>
              ) : null}
            </dl>

            {managing.allowedStatuses.length === 0 ? (
              <InlineNotification
                kind="info"
                lowContrast
                hideCloseButton
                title="No further changes"
                subtitle="A blacklisted supplier cannot be moved to another status."
              />
            ) : (
              <>
                <Select
                  id="supplier-target-status"
                  labelText="New status"
                  value={statusForm.data.target_status}
                  onChange={(event) => statusForm.setData('target_status', event.currentTarget.value)}
                  invalid={Boolean(statusForm.errors.target_status)}
                  invalidText={statusForm.errors.target_status}
                >
                  {managing.allowedStatuses.map((status) => (
                    <SelectItem key={status} value={status} text={formatStatus(status)} />
                  ))}
                </Select>

                {statusForm.data.target_status === 'BLACKLISTED' ? (
                  <InlineNotification
                    kind="warning"
                    lowContrast
                    hideCloseButton
                    title="Blacklisting cannot be undone"
                    subtitle="The supplier is blocked from new orders for good. Existing issued orders are not cancelled."
                  />
                ) : null}

                <TextArea
                  id="supplier-status-reason"
                  labelText="Reason"
                  helperText="Stored on the supplier and in the audit trail."
                  rows={3}
                  value={statusForm.data.reason}
                  onChange={(event) => statusForm.setData('reason', event.currentTarget.value)}
                  invalid={Boolean(statusForm.errors.reason)}
                  invalidText={statusForm.errors.reason}
                  required
                />
              </>
            )}
          </Stack>
        ) : null}
      </DockedPanel>
    </section>
  );
}
