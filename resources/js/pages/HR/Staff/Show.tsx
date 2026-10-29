import { HRWorkflowFeedback } from '@/features/hr/components/HRWorkflowFeedback';
import {
  StaffProfile,
  type StaffProfileData,
  type StaffQualification,
  type TerminationBlockers,
} from '@/features/hr/components/StaffProfile';
import type { FacilitySummary } from '@/features/hr/types';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface StaffShowPageProps {
  facility: FacilitySummary;
  staff: StaffProfileData;
  qualifications: StaffQualification[];
  canReadQualifications: boolean;
  terminationBlockers: TerminationBlockers;
  canManagePerson: boolean;
  canManageEmployment: boolean;
  canManageStaff: boolean;
  canCreateStaff: boolean;
  canLinkStaff: boolean;
  canInviteUser: boolean;
  canResendInvitation: boolean;
  canRevokeInvitation?: boolean;
  canUnlinkAccount?: boolean;
  invitationRoles: { key: string; label: string }[];
  pendingInvitationAccessRequests: { id: string; roleKey: string; roleLabel: string }[];
  invitationHistory?: import('@/features/hr/types').InvitationTimelineEntry[];
  accessAssignments?: {
    id: string;
    role: string;
    roleName: string;
    scope: string;
    status: string;
    effectiveFrom: string | null;
    effectiveTo: string | null;
    isEffective: boolean;
    isLifecycleSuspended: boolean;
    canManageIndividually: boolean;
  }[];
  accessHistory?: {
    action: string;
    role: string;
    actorName: string;
    reason: string | null;
    occurredAt: string;
  }[];
  canManageStaffAccess?: boolean;
  canReadAccessHistory?: boolean;
  canManagePositions: boolean;
  canReviewAssignments: boolean;
  errors?: Record<string, string>;
}

export default function StaffShowPage(props: StaffShowPageProps): React.JSX.Element {
  return (
    <AppLayout>
      <StaffProfile
        staff={props.staff}
        qualifications={props.qualifications}
        canReadQualifications={props.canReadQualifications}
        terminationBlockers={props.terminationBlockers}
        canManagePerson={props.canManagePerson}
        canManageEmployment={props.canManageEmployment}
        canManageStaff={props.canManageStaff}
        canLinkStaff={props.canLinkStaff}
        canInviteUser={props.canInviteUser}
        canResendInvitation={props.canResendInvitation}
        canRevokeInvitation={props.canRevokeInvitation}
        canUnlinkAccount={props.canUnlinkAccount}
        invitationRoles={props.invitationRoles}
        pendingInvitationAccessRequests={props.pendingInvitationAccessRequests}
        invitationHistory={props.invitationHistory}
        accessAssignments={props.accessAssignments}
        accessHistory={props.accessHistory}
        canManageStaffAccess={props.canManageStaffAccess}
        canReadAccessHistory={props.canReadAccessHistory}
        feedback={<HRWorkflowFeedback errors={props.errors} />}
      />
    </AppLayout>
  );
}
