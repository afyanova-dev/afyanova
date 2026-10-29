import { MenuItem } from '@carbon/react';
import type { ReactNode } from 'react';
import { Add, Checkmark, Edit, OverflowMenuVertical, Pending, Renew, UserFollow, UserMinus, UserProfile } from '@/icons';
import { IconMenuButton } from '@/components/ui';
import type { StaffProfileData } from './StaffProfile';

export type StaffWorkspaceKind =
  | 'personal'
  | 'onboard'
  | 'employment-start'
  | 'suspend'
  | 'terminate'
  | 'invite'
  | 'resend-invitation'
  | 'link';

/**
 * OpenMRS-style Actions menu for the staff header.
 *
 * Text-labelled menu button with a three-dots trigger icon (`Actions`)
 * exposing the record-level actions. Items mirror their permission and
 * lifecycle gates with `disabled` so unavailable actions are visible but
 * not clickable (same pattern as the patient chart Actions menu).
 */
export function StaffActionsMenu({
  staff,
  canManagePerson,
  canManageEmployment,
  canManageStaff,
  canLinkStaff,
  canInviteUser,
  onWorkspace,
  onReinstate,
}: {
  staff: StaffProfileData;
  canManagePerson: boolean;
  canManageEmployment: boolean;
  canManageStaff: boolean;
  canLinkStaff: boolean;
  canInviteUser?: boolean;
  onWorkspace: (kind: StaffWorkspaceKind) => void;
  onReinstate: () => void;
}): ReactNode {
  const isTerminated = staff.status === 'TERMINATED';
  const canOnboard = canManageStaff
    && staff.hasCurrentFacilityAssignment
    && staff.status === 'PROSPECT';
  const canStartEmployment = canManageEmployment
    && staff.hasCurrentFacilityAssignment
    && staff.status === 'ACTIVE'
    && staff.employment?.status === 'DRAFT';
  const canSuspend = canManageStaff && staff.status === 'ACTIVE';
  const canReinstate = canManageStaff && staff.status === 'SUSPENDED';
  const canTerminate = canManageStaff && !isTerminated;
  const canInvite = Boolean(canInviteUser) && staff.linkedUserId === null;
  const canLink = canLinkStaff && staff.linkedUserId === null;

  function pick(kind: StaffWorkspaceKind): void {
    onWorkspace(kind);
  }

  return (
    <IconMenuButton
      label="Actions"
      icon={OverflowMenuVertical}
      kind="ghost"
      size="lg"
    >
      <MenuItem
        label="Edit personal details"
        renderIcon={Edit}
        disabled={!canManagePerson}
        onClick={() => {
          if (canManagePerson) pick('personal');
        }}
      />
      <MenuItem
        label="Complete onboarding"
        renderIcon={Add}
        disabled={!canOnboard}
        onClick={() => {
          if (canOnboard) pick('onboard');
        }}
      />
      <MenuItem
        label="Start employment"
        renderIcon={Checkmark}
        disabled={!canStartEmployment}
        onClick={() => {
          if (canStartEmployment) pick('employment-start');
        }}
      />
      <MenuItem
        label="Suspend staff"
        renderIcon={Pending}
        disabled={!canSuspend}
        onClick={() => {
          if (canSuspend) pick('suspend');
        }}
      />
      <MenuItem
        label="Reinstate staff"
        renderIcon={Renew}
        disabled={!canReinstate}
        onClick={() => {
          if (canReinstate) onReinstate();
        }}
      />
      <MenuItem
        label="Terminate staff"
        kind="danger"
        renderIcon={UserMinus}
        disabled={!canTerminate}
        onClick={() => {
          if (canTerminate) pick('terminate');
        }}
      />
      <MenuItem
        label="Invite account"
        renderIcon={UserFollow}
        disabled={!canInvite}
        onClick={() => {
          if (canInvite) pick('invite');
        }}
      />
      <MenuItem
        label="Link existing account"
        renderIcon={UserProfile}
        disabled={!canLink}
        onClick={() => {
          if (canLink) pick('link');
        }}
      />
    </IconMenuButton>
  );
}
