/**
 * Real backend routes — derived from `php artisan route:list`.
 * No invented paths. Workspaces without a GET Inertia route are omitted.
 */
export const routes = {
  home: '/',
  login: '/login',
  logout: '/logout',
  dashboard: '/dashboard',
  staffOnboardingPending: '/staff/onboarding-pending',
  health: '/system/health',
  password: {
    request: '/forgot-password',
    email: '/forgot-password',
    reset: (token: string) => `/reset-password/${token}`,
    update: '/reset-password',
    confirm: '/user/confirm-password',
  },
  twoFactor: {
    enable: '/user/two-factor-authentication',
    confirm: '/user/confirmed-two-factor-authentication',
    qrCode: '/user/two-factor-qr-code',
    secretKey: '/user/two-factor-secret-key',
    recoveryCodes: '/user/two-factor-recovery-codes',
  },
  adminBootstrap: {
    complete: '/admin/first-access',
  },
  platformAdmin: {
    bootstrap: '/platform-admin/first-access',
    index: '/platform-admin',
    audit: '/platform-admin/audit',
    superAdmins: '/platform-admin/super-admins',
    superAdminAccessRequests: '/platform-admin/super-admin-access-requests',
    reviewSuperAdminAccessRequest: (requestId: string) =>
      `/platform-admin/super-admin-access-requests/${requestId}/review`,
    search: '/platform-admin/search',
    tenants: '/platform-admin/tenants',
    createTenant: '/platform-admin/tenants/create',
    storeTenant: '/platform-admin/tenants',
    tenant: (tenantId: string) => `/platform-admin/tenants/${tenantId}`,
    updateTenantStatus: (tenantId: string) => `/platform-admin/tenants/${tenantId}/status`,
    initialTenantAdmin: (tenantId: string) => `/platform-admin/tenants/${tenantId}/tenant-admin-invitations`,
    revokeTenantAdmin: (tenantId: string) => `/platform-admin/tenants/${tenantId}/tenant-admin-revocations`,
    reviewTenantAdminRevocation: (tenantId: string, reviewId: string) => `/platform-admin/tenants/${tenantId}/tenant-admin-revocations/${reviewId}/review`,
    facility: (tenantId: string, facilityId: string) =>
      `/platform-admin/tenants/${tenantId}/facilities/${facilityId}`,
    updateFacility: (tenantId: string, facilityId: string) =>
      `/platform-admin/tenants/${tenantId}/facilities/${facilityId}`,
    revokeFacilityAdmin: (tenantId: string, facilityId: string) =>
      `/platform-admin/tenants/${tenantId}/facilities/${facilityId}/facility-admin-revocations`,
    twoFactorSetup: '/platform-admin/security/two-factor',
  },
  twoFactorChallenge: '/two-factor-challenge',
  verification: {
    notice: '/email/verify',
    send: '/email/verification-notification',
  },
  facilityContext: '/facility-context',
  sessions: {
    index: '/settings/sessions',
    destroy: (id: string) => `/settings/sessions/${id}`,
  },
  workspaces: {
    admin: '/workspaces/admin',
    hrStaff: '/workspaces/hr/staff',
    hrPeopleSearch: '/workspaces/hr/api/search',
    hrUserDetail: (userId: string) => `/workspaces/hr/users/${userId}`,
    hrStaffNew: '/workspaces/hr/staff/create',
    hrStaffDetail: (staffId: string) => `/workspaces/hr/staff/${staffId}`,
    hrStaffOnboarding: '/workspaces/hr/staff/onboarding',
    hrStaffAssignments: '/workspaces/hr/staff/assignment-requests',
    hrStaffAccountInvitations: '/workspaces/hr/staff/account-invitations',
    hrStaffAccessRequests: '/workspaces/hr/staff/access-requests',
    hrStaffPositionSetup: '/workspaces/hr/staff/positions',
    hrStaffCreate: '/workspaces/hr/staff',
    hrStaffPerson: (staffId: string) => `/workspaces/hr/staff/${staffId}/person`,
    hrStaffQualifications: (staffId: string) => `/workspaces/hr/staff/${staffId}/qualifications`,
    hrStaffQualification: (staffId: string, qualificationId: string) =>
      `/workspaces/hr/staff/${staffId}/qualifications/${qualificationId}`,
    hrStaffPhoto: (staffId: string) => `/workspaces/hr/staff/${staffId}/photo`,
    hrStaffOnboard: (staffId: string) => `/workspaces/hr/staff/${staffId}/onboard`,
    hrStaffSuspend: (staffId: string) => `/workspaces/hr/staff/${staffId}/suspend`,
    hrStaffReinstate: (staffId: string) => `/workspaces/hr/staff/${staffId}/reinstate`,
    hrStaffTerminate: (staffId: string) => `/workspaces/hr/staff/${staffId}/terminate`,
    hrStaffEmploymentStart: (staffId: string, employmentId: string) =>
      `/workspaces/hr/staff/${staffId}/employments/${employmentId}/start`,
    hrStaffCadres: '/workspaces/hr/staff/positions/cadres',
    hrStaffPositions: '/workspaces/hr/staff/positions',
    hrStaffAssignmentDecision: (requestId: string) =>
      `/workspaces/hr/staff/assignment-requests/${requestId}/decision`,
    hrStaffAssignmentResubmit: (requestId: string) =>
      `/workspaces/hr/staff/assignment-requests/${requestId}/resubmit`,
    hrStaffLink: (staffId: string) => `/workspaces/hr/staff/${staffId}/link`,
    hrStaffInviteAccount: (staffId: string) => `/workspaces/hr/staff/${staffId}/invite-account`,
    hrStaffResendInvitation: (staffId: string) => `/workspaces/hr/staff/${staffId}/resend-invitation`,
    hrStaffRevokeInvitation: (staffId: string) => `/workspaces/hr/staff/${staffId}/revoke-invitation`,
    hrStaffUnlinkAccount: (staffId: string) => `/workspaces/hr/staff/${staffId}/unlink-account`,
    hrStaffAccessAssignment: (staffId: string, assignmentId: string, action: 'suspend' | 'restore' | 'revoke') =>
      `/workspaces/hr/staff/${staffId}/access-assignments/${assignmentId}/${action}`,
    hrStaffAccessRequestApprove: (staffId: string, promotionId: string) =>
      `/workspaces/hr/access-requests/${promotionId}/staff/${staffId}/approve`,
    hrStaffAccessRequestReject: (staffId: string, promotionId: string) =>
      `/workspaces/hr/access-requests/${promotionId}/staff/${staffId}/reject`,
    adminOrganization: '/workspaces/admin/organization',
    adminOrganizationFacility: (facilityId: string) =>
      `/workspaces/admin/organization?facility_id=${encodeURIComponent(facilityId)}`,
    adminOrganizationFacilities: '/workspaces/admin/organization/facilities',
    adminOrganizationDepartments: '/workspaces/admin/organization/departments',
    adminOrganizationUnits: '/workspaces/admin/organization/units',
    adminOrganizationRooms: '/workspaces/admin/organization/rooms',
    adminOrganizationStructureRecord: (kind: 'departments' | 'units' | 'rooms', id: string) =>
      `/workspaces/admin/organization/${kind}/${encodeURIComponent(id)}`,
    adminOrganizationStructureStatus: (kind: 'departments' | 'units' | 'rooms', id: string) =>
      `/workspaces/admin/organization/${kind}/${encodeURIComponent(id)}/status`,
    /** Facility capability state change (`admin.workspace.organization.capabilities.status`, POST). */
    adminOrganizationCapabilityStatus: (capability: string) =>
      `/workspaces/admin/organization/capabilities/${encodeURIComponent(capability)}/status`,
    adminAudit: '/workspaces/admin/audit',
    /** Procurement single-actor approval limits (per facility / per department). */
    adminProcurementLimits: '/workspaces/admin/procurement-limits',
    adminRolePromotions: '/workspaces/admin/identity/role-promotions',
    adminFacilityUserInvitations: '/workspaces/admin/identity/role-promotions/facility-user-invitations',
    adminFacilityUserInvitationResend: (invitationId: string) =>
      `/workspaces/admin/identity/role-promotions/facility-user-invitations/${encodeURIComponent(invitationId)}/resend`,
    adminFacilityStaffManagers: '/workspaces/admin/identity/facility-staff-managers',
    adminFacilityStaffManager: (assignmentId: string) =>
      `/workspaces/admin/identity/facility-staff-managers/${assignmentId}`,
    adminFacilityAssignments: '/workspaces/admin/identity/facility-assignments',
    adminFacilityAssignmentTargets: '/workspaces/admin/identity/facility-assignment-targets',
    adminFacilityAssignment: (assignmentId: string) =>
      `/workspaces/admin/identity/facility-assignments/${assignmentId}`,
    adminRolePromotionApprove: (promotionId: string) =>
      `/workspaces/admin/identity/role-promotions/${promotionId}/approve`,
    adminRolePromotionReject: (promotionId: string) =>
      `/workspaces/admin/identity/role-promotions/${promotionId}/reject`,
    attention: '/workspaces/attention',
    serviceQueues: '/workspaces/service-queues',
    serviceQueue: (queueId: string) => `/workspaces/service-queues/${queueId}`,
    nurseStation: '/workspaces/nurse-station',
    nurseStationEntry: (entryId: string) => `/workspaces/nurse-station/entries/${entryId}`,
    nurseStationStart: (entryId: string) => `/workspaces/nurse-station/entries/${entryId}/start`,
    nurseStationHandoff: (entryId: string) => `/workspaces/nurse-station/entries/${entryId}/handoff`,
    clinician: '/workspaces/clinician',
    clinicianEntry: (entryId: string) => `/workspaces/clinician/entries/${entryId}`,
    encounterVitals: (encounterId: string) => `/workspaces/clinical/encounters/${encounterId}/vitals`,
    encounterTriage: (encounterId: string) => `/workspaces/clinical/encounters/${encounterId}/triage`,
    encounterNote: (encounterId: string) => `/workspaces/clinical/encounters/${encounterId}/notes`,
    encounterDiagnosis: (encounterId: string) => `/workspaces/clinical/encounters/${encounterId}/diagnoses`,
    encounterPlan: (encounterId: string) => `/workspaces/clinical/encounters/${encounterId}/plans`,
    encounterProcedureRequest: (encounterId: string) => `/workspaces/clinical/encounters/${encounterId}/procedures`,
    procedureAuthorize: (encounterId: string, procedureId: string) =>
      `/workspaces/clinical/encounters/${encounterId}/procedures/${procedureId}/authorize`,
    procedureWork: (entryId: string) => `/workspaces/clinical/procedure-tickets/${entryId}`,
    procedureWorkUpdate: (entryId: string) => `/workspaces/clinical/procedure-tickets/${entryId}`,
    /** Queue-specific room routing (`queue.definition.manage`, PUT). */
    queueRooms: (queueId: string) => `/workspaces/service-queues/queues/${queueId}/rooms`,
    queueRoomStaff: (queueId: string, roomId: string) => `/workspaces/service-queues/queues/${queueId}/rooms/${roomId}/staff`,
    /** Configure automatic procedure-definition-to-queue routing. */
    procedureQueueRouting: '/workspaces/service-queues/procedure-routing',
    procedureProtocolApprove: '/workspaces/clinical/procedure-protocol-approvals',
    procedureProtocolRevoke: (approvalId: string) =>
      `/workspaces/clinical/procedure-protocol-approvals/${approvalId}/revoke`,
    /** Ticket call (`queue.ticket.call`, POST with room). */
    ticketCall: (entryId: string) => `/workspaces/service-queues/entries/${entryId}/call`,
    /** Ticket recall (`queue.ticket.call`, POST, room optional). */
    ticketRecall: (entryId: string) => `/workspaces/service-queues/entries/${entryId}/recall`,
    /** Start service (`queue.ticket.service`, POST). */
    ticketStart: (entryId: string) => `/workspaces/service-queues/entries/${entryId}/start`,
    /** Complete service (`queue.ticket.service`, POST). */
    ticketComplete: (entryId: string) => `/workspaces/service-queues/entries/${entryId}/complete`,
    /** Hold ticket (`queue.ticket.hold`, POST with reason + provenance). */
    ticketHold: (entryId: string) => `/workspaces/service-queues/entries/${entryId}/hold`,
    /** Release hold (`queue.ticket.hold`, POST with clearance provenance). */
    ticketRelease: (entryId: string) => `/workspaces/service-queues/entries/${entryId}/release`,
    /** Skip ticket (`queue.ticket.skip` or call, POST). */
    ticketSkip: (entryId: string) => `/workspaces/service-queues/entries/${entryId}/skip`,
    /** Transfer ticket to another facility queue (`queue.ticket.transfer`, POST). */
    ticketTransfer: (entryId: string) => `/workspaces/service-queues/entries/${entryId}/transfer`,
    reception: '/workspaces/reception',
    receptionCheckIn: '/workspaces/reception/check-in',
    receptionTransfer: '/workspaces/reception/transfer',
    receptionStart: (encounterId: string) => `/workspaces/reception/encounters/${encounterId}/start`,
    receptionDisposition: (encounterId: string) => `/workspaces/reception/encounters/${encounterId}/disposition`,
    receptionComplete: (encounterId: string) => `/workspaces/reception/encounters/${encounterId}/complete`,
    appointments: '/workspaces/appointments',
    appointmentCalendar: '/workspaces/appointments/calendar',
    appointmentReschedule: (appointmentId: string) => `/workspaces/appointments/${appointmentId}/reschedule`,
    appointmentCancel: (appointmentId: string) => `/workspaces/appointments/${appointmentId}/cancel`,
    appointmentConfirm: (appointmentId: string) => `/workspaces/appointments/${appointmentId}/confirm`,
    appointmentNoShow: (appointmentId: string) => `/workspaces/appointments/${appointmentId}/no-show`,
    /** Appointment arrival (`scheduling.appointments.arrive`, POST). */
    appointmentArrive: (appointmentId: string) => `/workspaces/appointments/${appointmentId}/arrive`,
    billing: '/workspaces/billing',
    billingOpenCashSession: '/workspaces/billing/cash-sessions/open',
    billingCloseCashSession: (sessionId: string) => `/workspaces/billing/cash-sessions/${sessionId}/close`,
    billingInvoices: '/workspaces/billing/invoices',
    billingInvoiceIssue: (invoiceId: string) => `/workspaces/billing/invoices/${invoiceId}/issue`,
    billingPayments: '/workspaces/billing/payments',
    billingPaymentAllocate: '/workspaces/billing/payments/allocate',
    billingCatalog: '/workspaces/billing/catalog',
    /** Add a billing service to the price list (`billing.catalog.services.store`, POST). */
    billingCatalogServices: '/workspaces/billing/catalog/services',
    /** Rename / re-category a service (`billing.catalog.services.update`, PATCH). */
    billingCatalogServiceUpdate: (billingServiceId: string) =>
      `/workspaces/billing/catalog/services/${billingServiceId}`,
    /** Activate / deactivate a service (`billing.catalog.services.status`, POST). */
    billingCatalogServiceStatus: (billingServiceId: string) =>
      `/workspaces/billing/catalog/services/${billingServiceId}/status`,
    /** Set a new tariff row (`billing.catalog.tariffs.store`, POST). */
    billingCatalogTariffs: '/workspaces/billing/catalog/tariffs',
    /** End an open tariff row (`billing.catalog.tariffs.end`, POST). */
    billingCatalogTariffsEnd: '/workspaces/billing/catalog/tariffs/end',
    /** Map a medication to an active service (`billing.catalog.item-maps.store`, POST). */
    billingCatalogItemMaps: '/workspaces/billing/catalog/item-maps',
    /** Remove a medication's active mapping (`billing.catalog.item-maps.deactivate`, POST). */
    billingCatalogItemMapsDeactivate: '/workspaces/billing/catalog/item-maps/deactivate',
    /** Re-attempt a pending billable event (`billing.catalog.events.retry`, POST). */
    billingCatalogEventRetry: (billableEventId: string) =>
      `/workspaces/billing/catalog/events/${billableEventId}/retry`,
    pharmacy: '/workspaces/pharmacy',
    inventory: '/workspaces/inventory',
    /** Procurement workspace (Shape B — suppliers, then orders + requests). */
    procurement: '/workspaces/procurement',
    procurementSuppliersStore: '/workspaces/procurement/suppliers',
    procurementSupplierStatus: (supplierId: string) =>
      `/workspaces/procurement/suppliers/${supplierId}/status`,
    procurementSupplierLicence: (supplierId: string) =>
      `/workspaces/procurement/suppliers/${supplierId}/licence`,
    procurementItemPrices: '/workspaces/procurement/orders/item-prices',
    procurementRequestsStore: '/workspaces/procurement/requests',
    procurementRequestsExport: '/workspaces/procurement/requests/export.xlsx',
    procurementRequestPdf: (requisitionId: string) =>
      `/workspaces/procurement/requests/${requisitionId}/pdf`,
    procurementRequestUpdate: (requisitionId: string) =>
      `/workspaces/procurement/requests/${requisitionId}`,
    procurementRequestSubmit: (requisitionId: string) =>
      `/workspaces/procurement/requests/${requisitionId}/submit`,
    procurementRequestApprove: (requisitionId: string) =>
      `/workspaces/procurement/requests/${requisitionId}/approve`,
    procurementRequestReject: (requisitionId: string) =>
      `/workspaces/procurement/requests/${requisitionId}/reject`,
    procurementRequestCancel: (requisitionId: string) =>
      `/workspaces/procurement/requests/${requisitionId}/cancel`,
    procurementOrdersStore: '/workspaces/procurement/orders',
    procurementOrdersExport: '/workspaces/procurement/orders/export.xlsx',
    procurementOrderPdf: (purchaseOrderId: string) =>
      `/workspaces/procurement/orders/${purchaseOrderId}/pdf`,
    procurementOrderSubmit: (purchaseOrderId: string) =>
      `/workspaces/procurement/orders/${purchaseOrderId}/submit`,
    procurementOrderApprove: (purchaseOrderId: string) =>
      `/workspaces/procurement/orders/${purchaseOrderId}/approve`,
    procurementOrderIssue: (purchaseOrderId: string) =>
      `/workspaces/procurement/orders/${purchaseOrderId}/issue`,
    procurementOrderCancel: (purchaseOrderId: string) =>
      `/workspaces/procurement/orders/${purchaseOrderId}/cancel`,
    procurementOrderCloseShort: (purchaseOrderId: string) =>
      `/workspaces/procurement/orders/${purchaseOrderId}/close-short`,
    inventoryItemsStore: '/workspaces/inventory/items',
    inventoryItemStatus: (itemId: string) => `/workspaces/inventory/items/${itemId}/status`,
    /** Per-facility minimum level for one item (POST). */
    inventoryItemLevelsStore: '/workspaces/inventory/item-levels',
    inventoryLocationsStore: '/workspaces/inventory/locations',
    inventoryLocationStatus: (locationId: string) => `/workspaces/inventory/locations/${locationId}/status`,
    inventoryLocationTypesStore: '/workspaces/inventory/location-types',
    inventoryGoodsReceiptsStore: '/workspaces/inventory/goods-receipts',
    inventoryGoodsReceiptPrint: (goodsReceiptId: string) =>
      `/workspaces/inventory/goods-receipts/${goodsReceiptId}/print`,
    inventoryGoodsReceiptInvoice: (goodsReceiptId: string) =>
      `/workspaces/inventory/goods-receipts/${goodsReceiptId}/invoice`,
    inventoryGoodsReceiptMatch: (goodsReceiptId: string) =>
      `/workspaces/inventory/goods-receipts/${goodsReceiptId}/match`,
    inventoryGoodsReceiptReturn: (goodsReceiptId: string) =>
      `/workspaces/inventory/goods-receipts/${goodsReceiptId}/returns`,
    inventoryGoodsReceiptMatchExport: (goodsReceiptId: string) =>
      `/workspaces/inventory/goods-receipts/${goodsReceiptId}/invoice-match/export`,
    inventoryReceiptOpenOrders: '/workspaces/inventory/receipts/open-orders',
    inventoryReceiptOpenOrderLines: (purchaseOrderId: string) =>
      `/workspaces/inventory/receipts/open-orders/${purchaseOrderId}/lines`,
    inventoryTransfersStore: '/workspaces/inventory/transfers',
    inventoryStockCountsStore: '/workspaces/inventory/stock-counts',
    inventoryStockCountCounts: (stockCountId: string) =>
      `/workspaces/inventory/stock-counts/${stockCountId}/counts`,
    inventoryStockCountLines: (stockCountId: string) =>
      `/workspaces/inventory/stock-counts/${stockCountId}/lines`,
    inventoryStockCountClose: (stockCountId: string) =>
      `/workspaces/inventory/stock-counts/${stockCountId}/close`,
    inventoryAdjustmentsStore: '/workspaces/inventory/adjustments',
    inventoryAdjustmentApprove: (adjustmentId: string) => `/workspaces/inventory/adjustments/${adjustmentId}/approve`,
    inventoryAdjustmentReject: (adjustmentId: string) => `/workspaces/inventory/adjustments/${adjustmentId}/reject`,
    /** Paper transcription (`pharmacy.prescriptions.transcribe`, POST). */
    pharmacyTranscribe: '/workspaces/pharmacy/prescriptions/transcribe',
    /** External-paper pharmacy-only patient registration (POST). */
    pharmacyExternalPatient: '/workspaces/pharmacy/external-patients',
    /** Electronic prescription acceptance (`pharmacy.prescriptions.accept`, POST). */
    pharmacyPrescriptionAccept: (prescriptionId: string) =>
      `/workspaces/pharmacy/prescriptions/${prescriptionId}/accept`,
    /** Prescription cancellation (`pharmacy.prescriptions.cancel`, POST). */
    pharmacyPrescriptionCancel: (prescriptionId: string) =>
      `/workspaces/pharmacy/prescriptions/${prescriptionId}/cancel`,
    /** Pharmacy order creation (`pharmacy.orders.store`, POST). */
    pharmacyOrdersStore: '/workspaces/pharmacy/orders',
    /** Order validation (`pharmacy.orders.validate`, POST). */
    pharmacyOrderValidate: (orderId: string) => `/workspaces/pharmacy/orders/${orderId}/validate`,
    /** Order rejection (`pharmacy.orders.reject`, POST). */
    pharmacyOrderReject: (orderId: string) => `/workspaces/pharmacy/orders/${orderId}/reject`,
    /** Order hold (`pharmacy.orders.hold`, POST). */
    pharmacyOrderHold: (orderId: string) => `/workspaces/pharmacy/orders/${orderId}/hold`,
    /** Hold release (`pharmacy.orders.release`, POST). */
    pharmacyOrderRelease: (orderId: string) => `/workspaces/pharmacy/orders/${orderId}/release`,
    /** Order cancellation (`pharmacy.orders.cancel`, POST). */
    pharmacyOrderCancel: (orderId: string) => `/workspaces/pharmacy/orders/${orderId}/cancel`,
    /** Dispensing (`pharmacy.dispensings.store`, POST). */
    pharmacyDispensingsStore: '/workspaces/pharmacy/dispensings',
    /** Dispensing reversal (`pharmacy.dispensings.reverse`, POST). */
    pharmacyDispensingReverse: (dispensingId: string) =>
      `/workspaces/pharmacy/dispensings/${dispensingId}/reverse`,
    /** Pickup recording (`pharmacy.pickups.store`, POST). */
    pharmacyPickupsStore: '/workspaces/pharmacy/pickups',
    /** Not-collected outcome (`pharmacy.pickups.not-collected`, POST). */
    pharmacyPickupNotCollected: '/workspaces/pharmacy/pickups/not-collected',
    /** Pickup cancellation (`pharmacy.pickups.cancel`, POST). */
    pharmacyPickupCancel: '/workspaces/pharmacy/pickups/cancel',
    patientIndex: '/workspaces/patient',
    patientRegister: '/workspaces/patient/register',
    patientRegistrationDraft: '/workspaces/patient/register/draft',
  },
  patient: {
    /**
     * Canonical chart URL (`patient.chart`). The legacy workspace alias
     * serves the same controller — new code must point here (backend
     * routes.php requires TS to use the canonical URL).
     */
    chart: (patientId: string, tab?: string) =>
      tab ? `/patient/${patientId}/chart/${tab}` : `/patient/${patientId}/chart`,
    photo: (patientId: string) => `/patient/${patientId}/photo`,
    /** Demographics update (`patient.update`, PATCH). */
    update: (patientId: string) => `/workspaces/patient/${patientId}`,
    /** Mark patient deceased (`patient.mark-deceased`, POST). */
    markDeceased: (patientId: string) => `/workspaces/patient/${patientId}/mark-deceased`,
    /** Appointment booking (`scheduling.appointments.book`, POST). */
    bookAppointment: (patientId: string) => `/patient/${patientId}/appointments`,
  },
  api: {
    patientSearch: '/workspaces/patient/api/search',
    facilityStructure: '/workspaces/organization/api/structure',
    appointmentCalendar: '/workspaces/appointments/api/calendar',
    appointmentOptions: '/workspaces/scheduling/appointment-options',
    availableSlots: '/workspaces/scheduling/available-slots',
  },
} as const;
