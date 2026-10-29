import { router, usePage } from '@inertiajs/react';
import { ActionableNotification, Modal, RadioButton, RadioButtonGroup } from '@carbon/react';
import { useEffect, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import {
  OrdersSection,
  RequestsSection,
  SuppliersSection,
  type ProcurementWorkspaceProps,
} from '@/features/procurement';
import { AppLayout } from '@/layouts/AppLayout';
import type { NavigationItem } from '@/layouts/AppLayout/navigation';
import type { SharedProps } from '@/types';

export default function ProcurementIndex({
  section,
  facilityContextRequired,
  suppliers,
  supplierStatuses,
  canManageSuppliers,
  canCreateOrders,
  canCreateRequests,
  canCreateForAnyDepartment,
  currentUserId,
  purchaseOrders,
  purchaseRequisitions,
  departments,
  items,
  effectiveApprovalLimit,
  initialRequest,
  orderableRequests,
}: ProcurementWorkspaceProps): ReactNode {
  const { props } = usePage<SharedProps>();
  const { auth } = props;
  const [facilityDialogOpen, setFacilityDialogOpen] = useState(facilityContextRequired);
  const [selectedFacility, setSelectedFacility] = useState('');
  const [requestedSection, setRequestedSection] = useState(section);

  useEffect(() => {
    if (facilityContextRequired) {
      setRequestedSection(section);
      setFacilityDialogOpen(true);
    }
  }, [facilityContextRequired, section]);

  function navigateToSection(item: NavigationItem): void {
    const targetSection = item.id.replace(/^procurement-/, '');
    const requiresFacility = targetSection === 'requests' || targetSection === 'orders';

    if (requiresFacility && (auth.contextMode !== 'facility' || auth.activeFacility === null)) {
      setRequestedSection(targetSection);
      setSelectedFacility('');
      setFacilityDialogOpen(true);
      return;
    }

    router.visit(item.href);
  }

  function switchFacility(): void {
    if (selectedFacility === '') return;

    router.post(routes.facilityContext, { facility_id: selectedFacility }, {
      onSuccess: () => {
        setFacilityDialogOpen(false);
        router.visit(`${routes.workspaces.procurement}?section=${requestedSection}`);
      },
    });
  }

  return (
    <AppLayout
      activeSidebarItemId={`procurement-${section}`}
      onSidebarItemClick={navigateToSection}
    >
      <main className="afyanova-page procurement-workspace">
        {/* Inventory-pattern workspace: no fixed page header. The module and
            section names live in the navigation and the table titles; context
            signals (the facility gate) are notifications at the top, and each
            section explains itself with its own info alert. */}
        {facilityContextRequired ? (
          <ActionableNotification
            kind="info"
            title="Choose a facility"
            subtitle={`Procurement ${section === 'orders' ? 'orders' : 'requests'} belong to a facility. Choose where you want to work.`}
            actionButtonLabel="Choose a facility"
            onActionButtonClick={() => setFacilityDialogOpen(true)}
            hideCloseButton
          />
        ) : null}

        {section === 'requests' && !facilityContextRequired ? (
          <RequestsSection
            requests={purchaseRequisitions}
            departments={departments}
            items={items}
            currentUserId={currentUserId}
            canCreate={canCreateRequests}
            canCreateForAnyDepartment={canCreateForAnyDepartment}
          />
        ) : null}

        {section === 'suppliers' ? (
          <SuppliersSection
            suppliers={suppliers}
            statuses={supplierStatuses}
            canManage={canManageSuppliers}
          />
        ) : null}

        {section === 'orders' && !facilityContextRequired ? (
          <OrdersSection
            orders={purchaseOrders}
            suppliers={suppliers}
            items={items}
            currentUserId={currentUserId}
            canCreate={canCreateOrders}
            approvalLimit={effectiveApprovalLimit}
            initialRequest={initialRequest}
            orderableRequests={orderableRequests}
          />
        ) : null}
      </main>
      <Modal
        open={facilityDialogOpen}
        modalHeading={`Choose a facility for ${requestedSection === 'orders' ? 'purchase orders' : 'requests'}`}
        primaryButtonText="Continue"
        secondaryButtonText="Cancel"
        primaryButtonDisabled={selectedFacility === ''}
        onRequestClose={() => setFacilityDialogOpen(false)}
        onRequestSubmit={switchFacility}
      >
        {auth.facilities.length > 0 ? (
          <>
            <p>Select an authorized facility. AfyaNova will switch your working context and open the requested section.</p>
            <RadioButtonGroup
              legendText="Available facilities"
              name="procurement-facility"
              valueSelected={selectedFacility || undefined}
              onChange={(value) => setSelectedFacility(String(value))}
            >
              {auth.facilities.map((facility) => (
                <RadioButton
                  key={facility.id}
                  id={`procurement-facility-${facility.id}`}
                  value={facility.id}
                  labelText={facility.name}
                />
              ))}
            </RadioButtonGroup>
          </>
        ) : (
          <p>No facilities are available to your account. Ask an administrator to grant facility access.</p>
        )}
      </Modal>
    </AppLayout>
  );
}
