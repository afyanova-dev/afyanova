/**
 * Verified backend permission keys actually checked by the frontend.
 *
 * Each key was confirmed in its module's `Authorization/permissions.php`
 * (shape `{domain}.access` or `{domain}.{resource}.{action}` enforced by
 * `PermissionRegistry::assertShape`). Add keys here only after verifying them
 * against the backend — never invent permission names.
 *
 * Kept intentionally small: add constants as slices need them (§6), do not
 * copy the hundreds of backend keys up front.
 */
export const permissions = {
  admin: {
    /** Enter the tenant-scoped Admin workspace. */
    workspaceAccess: 'admin.workspace.access',
  },
  hr: {
    /** Open the facility-scoped staff directory and requests workspace. */
    staffRead: 'hr.staff.read',
    staffManage: 'hr.staff.manage',
    personManage: 'hr.person.manage',
    assignmentManage: 'hr.assignment.manage',
    positionManage: 'hr.cadre_position.manage',
    staffUserLinkManage: 'hr.staff_user_link.manage',
  },
  audit: {
    /** Read the tenant-scoped audit viewer. */
    eventsRead: 'audit.events.read',
  },
  identity: {
    /** Search facility-scoped user accounts. */
    usersRead: 'identity.users.read',
    /** Read user access assignments and administrative promotion requests. */
    assignmentsRead: 'identity.assignments.read',
    /** Confirm staff assignments within an authorized facility. */
    assignmentsManage: 'identity.assignments.manage',
  },
  queue: {
    /** Queueing/P12.1 — open the service-queues workspace. */
    ticketView: 'queue.ticket.view',
    /** Configure the rooms where each queue provides service. */
    definitionManage: 'queue.definition.manage',
    /** Reception check-in — issue a queue ticket alongside check-in. */
    ticketPlace: 'queue.ticket.place',
    /** Reception — move a patient to another service location. */
    ticketTransfer: 'queue.ticket.transfer',
    /** Service queues — call (and recall) a waiting ticket to a room. */
    ticketCall: 'queue.ticket.call',
    /** Service queues — start service on a called ticket, complete in-service. */
    ticketService: 'queue.ticket.service',
    /** Service queues — hold a waiting ticket and release it back. */
    ticketHold: 'queue.ticket.hold',
    /** Service queues — skip a waiting ticket (or call to skip). */
    ticketSkip: 'queue.ticket.skip',
  },
  clinical: {
    /** Clinical documentation and encounter workspace access. */
    access: 'clinical.access',
    /** Read and record encounter vital signs. */
    vitalsRead: 'clinical.vitals.read',
    vitalsRecord: 'clinical.vitals.record',
    /** Read and record encounter triage assessments. */
    triageRead: 'clinical.triage.read',
    triageRecord: 'clinical.triage.record',
    /** Open the clinician consultation workspace. */
    consultationsRead: 'clinical.consultations.read',
    noteCreate: 'clinical.notes.create',
    diagnosisRecord: 'clinical.diagnoses.record',
    planRecord: 'clinical.plans.record',
    /** Read the procedure catalog when configuring procedure queue routing. */
    proceduresRead: 'clinical.procedures.read',
    proceduresProtocolRequest: 'clinical.procedures.protocol_request',
    proceduresProtocolManage: 'clinical.procedures.protocol_manage',
  },
  scheduling: {
    /** Scheduling — coarse access to the appointments workspace. */
    access: 'sch.access',
    /** Submit appointment bookings (also gates exact-slot booking). */
    book: 'sch.appointment.book',
    /** Reschedule action on existing appointments. */
    manage: 'sch.appointment.manage',
    /** Record arrival on an appointment without creating a new encounter. */
    recordArrival: 'sch.appointment.record_arrival',
  },
  billing: {
    /** Billing — coarse access to the billing workspace. */
    access: 'billing.access',
  },
  pharmacy: {
    /** Pharmacy — coarse access to the pharmacy workspace. */
    access: 'pharmacy.access',
    /** Accept external paper prescriptions and cancel prescriptions. */
    prescriptionsAccept: 'pharmacy.prescriptions.accept',
    /** Create and manage pharmacy orders (validate/reject/hold/release/cancel). */
    ordersManage: 'pharmacy.orders.manage',
    /** Record dispensings against validated orders. */
    dispensingRecord: 'pharmacy.dispensing.record',
    /** Reverse a completed dispensing. */
    dispensingReverse: 'pharmacy.dispensing.reverse',
    /** Record pickups and not-collected/cancelled outcomes. */
    pickupRecord: 'pharmacy.pickup.record',
  },
  inventory: {
    /** Enter the facility-scoped Inventory workspace. */
    access: 'inventory.access',
    /** Record a goods receipt into a facility stock location. */
    receiptsCreate: 'inventory.receipts.create',
    /** Move stock between two locations of the same facility. */
    transfersCreate: 'inventory.transfers.create',
    /** Start and work a physical stock count at one location. */
    countsManage: 'inventory.counts.manage',
  },
  organization: {
    /** Admin organization/facility overview. */
    facilitiesRead: 'organization.facilities.read',
    /** Manage organization facilities. */
    facilitiesManage: 'organization.facilities.manage',
    /** Facility structure — read departments/units for check-in routing. */
    structureRead: 'organization.structure.read',
  },
  procurement: {
    /** Enter the facility-scoped Procurement workspace. */
    access: 'procurement.access',
    /** Register a supplier and move it through its lifecycle. */
    suppliersManage: 'procurement.suppliers.manage',
    /** Admin — set the per-facility / per-department single-actor approval limit. */
    approvalLimitsManage: 'procurement.approval_limits.manage',
  },
  patient: {
    /** Patient workspace — search and browse patient records. */
    read: 'patient.patients.read',
    /** Patient registration page. */
    create: 'patient.patients.create',
    /** Create provisional patient identities for external pharmacy prescriptions. */
    createPharmacyOnly: 'patient.patients.create_pharmacy_only',
    /** Demographic/address writes (also gates intake address storage). */
    update: 'patient.patients.update',
    /** Record a patient as deceased (terminal lifecycle transition). */
    markDeceased: 'patient.patients.mark_deceased',
    /** Register verified patient identifiers. */
    identifiersManage: 'patient.identifiers.manage',
    /** Override a REVIEW duplicate outcome. */
    overrideDuplicate: 'patient.patients.override_duplicate',
  },
  encounter: {
    /** Reception workspace — read today's attendance roster. */
    read: 'encounter.encounters.read',
    /** Reception check-in — create encounters. */
    create: 'encounter.encounters.create',
    /** Reception lifecycle — start an ARRIVED encounter (ARRIVED → ACTIVE). */
    update: 'encounter.encounters.update',
    /** Reception lifecycle — record/revise disposition on an ACTIVE encounter. */
    manageDisposition: 'encounter.encounters.manage_disposition',
    /** Reception lifecycle — complete an ACTIVE encounter with disposition. */
    complete: 'encounter.encounters.complete',
  },
} as const;
