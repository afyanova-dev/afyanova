export interface FacilitySummary {
  id: string;
  name: string;
}

export interface DepartmentOption {
  id: string;
  name: string;
}

export interface UnitOption {
  id: string;
  departmentId: string;
  name: string;
}

export interface PositionOption {
  id: string;
  code?: string;
  title: string;
  cadreId: string;
}

export interface CadreOption {
  id: string;
  code: string;
  name: string;
  category: string;
}

export interface StaffDirectoryRow {
  id: string;
  staffNumber: string;
  name: string;
  status: string;
  phone: string | null;
  email: string | null;
  positionTitle: string | null;
  departmentName?: string | null;
  unitName?: string | null;
  linkedUserId?: string | null;
  linkedUserEmail?: string | null;
  accountState?: 'NO_ACCOUNT' | 'INVITED' | 'INVITATION_EXPIRED' | 'INVITATION_REVOKED' | 'INVITATION_REPLACED' | 'SETUP_PENDING' | 'ACTIVE';
  invitationStatus?: string | null;
  invitationExpiresAt?: string | null;
  facilityId?: string;
}

export interface StaffDirectoryPagination {
  page: number;
  perPage: number;
  total: number;
  lastPage: number;
}

export interface FacilityInvitationRow {
  invitationId: string;
  userId: string;
  staffId: string | null;
  staffNumber: string | null;
  name: string;
  email: string;
  positionTitle: string | null;
  roles: string[];
  status: string;
  statusLabel: string;
  sentAt: string | null;
  expiresAt: string;
  expired: boolean;
  deliveryStatus: string;
  resendCount: number;
  lastSentByName: string | null;
  canResend: boolean;
  canRevoke: boolean;
  consumedAt: string | null;
  revokedAt: string | null;
  revocationReason: string | null;
}

export interface InvitationTimelineEntry {
  id: string;
  eventType: string;
  eventLabel: string;
  actorName: string;
  reason: string | null;
  occurredAt: string;
  recipientEmail: string | null;
  roles: string[];
  metadata: Record<string, unknown>;
}

/** @deprecated Use FacilityInvitationRow. */
export interface ExpiredStaffInvitationRow extends StaffDirectoryRow {
  email: string;
}

export interface RejectedAssignmentRequest {
  id: string;
  staffNumber: string;
  name: string;
  departmentId: string | null;
  unitId: string | null;
  departmentName: string | null;
  unitName: string | null;
  effectiveFrom: string;
  decisionReason: string | null;
}

export interface PendingAssignmentRequest {
  id: string;
  staffNumber: string;
  name: string;
  scope: string;
  departmentName: string | null;
  unitName: string | null;
  effectiveFrom: string;
}

export type OnboardingActionKind =
  | 'CONFIRM_PLACEMENT'
  | 'UPDATE_PLACEMENT'
  | 'REVIEW_INVITATION'
  | 'RESEND_INVITATION'
  | 'INVITE_ACCOUNT'
  | 'APPROVE_ACCESS'
  | 'COMPLETE_ONBOARDING'
  | 'START_EMPLOYMENT'
  | 'WAIT_PLACEMENT'
  | 'WAIT_ACCOUNT'
  | 'VIEW_PROFILE';

export interface StaffOnboardingRow {
  id: string;
  staffNumber: string;
  name: string;
  staffStatus: string;
  positionTitle: string | null;
  linkedUserId: string | null;
  accountEmail: string | null;
  accountVerifiedAt: string | null;
  placement: {
    state: 'CONFIRMED' | 'PENDING' | 'REJECTED' | 'NONE';
    label: string;
    requestId: string | null;
    departmentName: string | null;
    unitName: string | null;
    effectiveFrom: string | null;
    decisionReason: string | null;
  };
  accountState: string;
  invitation: {
    id: string | null;
    status: string | null;
    expiresAt: string | null;
    roleKeys: string[];
  };
  account: {
    state: string;
    label: string;
    email: string | null;
    expiresAt: string | null;
    invitationStatus: string | null;
  };
  access: {
    state: string;
    label: string;
    pendingCount: number;
    roleKeys: string[];
    roleLabels: string[];
  };
  employment: {
    state: string;
    label: string;
    status: string | null;
    startDate: string | null;
  };
  onboarding: {
    state: string;
    label: string;
  };
  nextAction: {
    kind: OnboardingActionKind;
    label: string;
  } | null;
  needsAction: boolean;
}

export interface StaffOnboardingSummary {
  total: number;
  needsAction: number;
  placements: number;
  invitations: number;
  expiredInvitations: number;
  access: number;
  readyToOnboard: number;
}

export type StaffOnboardingFocus =
  | 'needs-action'
  | 'in-progress'
  | 'all'
  | 'placements'
  | 'access'
  | 'invitations'
  | 'onboarding'
  | 'ready';
