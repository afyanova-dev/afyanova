import {
  Button,
  Checkbox,
  CheckboxGroup,
  InlineNotification,
  Modal,
  Select,
  SelectItem,
  Tag,
  TextArea,
  TextInput,
} from '@carbon/react';
import { router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { FormErrors } from '@/components/ui';
import { DiscardChangesModal, useWorkspaceGuard } from '@/components/workspace';
import { DockedPanel } from '@/components/panels';
import {
  Building,
  Calendar,
  Document,
  Edit,
  Locked,
  Time,
  UserFollow,
  UserMultiple,
  UserProfile,
} from '@/icons';
import { staffProfileSectionFromUrl } from '@/layouts/AppLayout/navigation';
import { formatDobDate } from '@/lib/formatting';
import { staffStatusLabel, staffStatusTagType } from '@/lib/staffStatus';
import { StaffActionsMenu, type StaffWorkspaceKind } from './StaffActionsMenu';
import { StaffBanner, staffAssignmentLabel } from './StaffBanner';
import type { InvitationTimelineEntry } from '../types';

interface PersonProfile {
  firstName: string;
  middleName: string | null;
  lastName: string;
  dateOfBirth: string;
  gender: string;
  nationalId: string | null;
  phone: string | null;
  email: string | null;
  physicalAddress: string | null;
  photoDocumentId: string | null;
}

interface Employment {
  id: string;
  contractType: string;
  status: string;
  startDate: string | null;
  fte: string;
  endDate: string | null;
  probationEndDate: string | null;
  terms: string | null;
}

export interface StaffProfileData {
  id: string;
  staffNumber: string;
  name: string;
  status: string;
  joinedDate: string | null;
  suspensionReason: string | null;
  terminatedDate: string | null;
  terminationReason: string | null;
  positionTitle: string | null;
  departmentName: string | null;
  unitName: string | null;
  assignmentScope: string;
  hasCurrentFacilityAssignment: boolean;
  pendingAssignmentRequestId?: string | null;
  pendingAssignmentEffectiveFrom?: string | null;
  phone: string | null;
  email: string | null;
  linkedUserId: string | null;
  linkedUserEmail: string | null;
  setupInvitation?: {
    id: string;
    status: string;
    sentAt: string | null;
    expiresAt: string;
    expired: boolean;
    canResend: boolean;
    canRevoke: boolean;
    deliveryStatus: string;
    lastSentByName: string | null;
    roles: string[];
  } | null;
  personProfile: PersonProfile | null;
  photoUrl: string | null;
  employment: Employment | null;
}

interface StaffAccessAssignment {
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
}

interface StaffAccessHistoryEntry {
  action: string;
  role: string;
  actorName: string;
  reason: string | null;
  occurredAt: string;
}

export interface StaffQualification {
  id: string;
  award: string;
  fieldOfStudy: string;
  institution: string;
  completionYear: number | null;
  verificationStatus: 'UNVERIFIED' | 'VERIFIED';
}

export interface TerminationBlockers {
  activeAssignments: number;
  openEmployments: number;
  futurePublishedShifts: number;
}

interface StaffProfileProps {
  staff: StaffProfileData;
  qualifications?: StaffQualification[];
  canReadQualifications?: boolean;
  terminationBlockers: TerminationBlockers;
  canManagePerson: boolean;
  canManageEmployment: boolean;
  canManageStaff: boolean;
  canLinkStaff: boolean;
  canInviteUser?: boolean;
  canResendInvitation?: boolean;
  canRevokeInvitation?: boolean;
  canUnlinkAccount?: boolean;
  invitationRoles?: { key: string; label: string }[];
  pendingInvitationAccessRequests?: { id: string; roleKey: string; roleLabel: string }[];
  invitationHistory?: InvitationTimelineEntry[];
  accessAssignments?: StaffAccessAssignment[];
  accessHistory?: StaffAccessHistoryEntry[];
  canManageStaffAccess?: boolean;
  canReadAccessHistory?: boolean;
  feedback?: ReactNode;
}

function readableLabel(value: string): string {
  return value
    .toLowerCase()
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((part) => part[0]!.toUpperCase() + part.slice(1))
    .join(' ');
}

function assignmentRoleLabel(role: string): string {
  return readableLabel(role);
}

/**
 * Compact staff identity row for workspace panels — photo (or initials),
 * name, staff number and position. Reuses the patient agenda identity
 * pattern (appointment-agenda__patient) so staff read the same way
 * patients do across workspaces.
 */
function StaffIdentityBlock({ staff }: { staff: StaffProfileData }): React.JSX.Element {
  const initials = staff.name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
  return (
    <div className="appointment-agenda__patient">
      {staff.photoUrl ? (
        <img className="appointment-agenda__photo" src={staff.photoUrl} alt="" width={32} height={32} />
      ) : (
        <span className="appointment-agenda__photo-fallback" aria-hidden="true">{initials}</span>
      )}
      <span className="appointment-agenda__patient-info">
        <span className="appointment-agenda__patient-name">{staff.name}</span>
        <span className="appointment-agenda__patient-number">
          {staff.staffNumber}{staff.positionTitle ? ` · ${staff.positionTitle}` : ''}
        </span>
      </span>
    </div>
  );
}

type StaffProfileTagType = 'green' | 'red' | 'blue' | 'cool-gray' | 'gray';

function displayDate(value: string | null | undefined, fallback = 'Not recorded'): string {
  return formatDobDate(value) ?? value ?? fallback;
}

function employmentStatusTagType(status: string): StaffProfileTagType {
  if (status === 'ACTIVE') return 'green';
  if (status === 'SUSPENDED') return 'red';
  if (status === 'DRAFT') return 'blue';

  return 'gray';
}

function accessAssignmentStatus(assignment: StaffAccessAssignment): string {
  if (assignment.isLifecycleSuspended) return 'Suspended by staff status';
  if (assignment.status === 'ACTIVE' && assignment.isEffective) return 'Active';
  if (assignment.status === 'ACTIVE') return 'Scheduled';

  return readableLabel(assignment.status);
}

function accessAssignmentTagType(assignment: StaffAccessAssignment): StaffProfileTagType {
  if (assignment.isLifecycleSuspended) return 'gray';
  if (assignment.status === 'ACTIVE' && assignment.isEffective) return 'green';
  if (assignment.status === 'ACTIVE') return 'blue';
  if (assignment.status === 'SUSPENDED') return 'red';

  return 'gray';
}

function lifecycleDescription(status: string): string {
  switch (status) {
    case 'ACTIVE':
      return 'The staff member is active and can hold employment and facility access.';
    case 'PROSPECT':
      return 'Complete onboarding to activate the staff record and start employment.';
    case 'ONBOARDING':
      return 'Onboarding is in progress. Finish the employment steps to activate the record.';
    case 'SUSPENDED':
      return 'Facility access through staff roles is suspended while this status remains in place.';
    case 'TERMINATED':
      return 'This staff record is closed. Historical details remain available for audit and reporting.';
    default:
      return 'The current staff lifecycle state for this record.';
  }
}

function ProfileSectionHeader({
  eyebrow,
  title,
  description,
  actions,
}: {
  eyebrow: string;
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
}): React.JSX.Element {
  return (
    <header className="staff-profile__section-heading">
      <div className="staff-profile__section-copy">
        <p className="staff-profile__eyebrow">{eyebrow}</p>
        <h2 className="staff-profile__section-title">{title}</h2>
        {description ? <p className="staff-profile__section-description">{description}</p> : null}
      </div>
      {actions ? <div className="staff-profile__section-actions">{actions}</div> : null}
    </header>
  );
}

function ProfileEmptyState({
  icon,
  title,
  description,
  actions,
}: {
  icon: ReactNode;
  title: string;
  description: ReactNode;
  actions?: ReactNode;
}): React.JSX.Element {
  return (
    <div className="afy-card staff-profile__empty">
      <span className="staff-profile__empty-icon" aria-hidden="true">{icon}</span>
      <div className="staff-profile__empty-copy">
        <h3 className="staff-profile__empty-title">{title}</h3>
        <p className="staff-profile__empty-description">{description}</p>
      </div>
      {actions ? <div className="staff-profile__empty-actions">{actions}</div> : null}
    </div>
  );
}

export function StaffProfile({
  staff,
  qualifications = [],
  canReadQualifications = true,
  terminationBlockers,
  canManagePerson,
  canManageEmployment,
  canManageStaff,
  canLinkStaff,
  canInviteUser = false,
  canResendInvitation = false,
  canRevokeInvitation = false,
  canUnlinkAccount = false,
  invitationRoles = [],
  pendingInvitationAccessRequests = [],
  invitationHistory = [],
  accessAssignments = [],
  accessHistory = [],
  canManageStaffAccess = false,
  canReadAccessHistory = false,
  feedback,
}: StaffProfileProps): React.JSX.Element {
  const profile = staff.personProfile;
  const { url } = usePage();
  const selectedSection = staffProfileSectionFromUrl(url);
  const shouldOpenResendInvitation = canResendInvitation
    && staff.setupInvitation?.canResend === true
    && new URLSearchParams(url.split('?')[1] ?? '').get('resend') === '1';
  const photoInputRef = useRef<HTMLInputElement>(null);
  const [photoPreviewUrl, setPhotoPreviewUrl] = useState<string | null>(null);

  /**
   * Single active workspace — one union instead of one boolean per panel.
   * Opening an unrelated workspace replaces the current one, so Edit never
   * stacks under Suspend/Terminate/Invite. `key` on each panel below remounts
   * the form on switch, discarding the previous draft via React itself.
   * Reinstate stays a direct action (no form to guard).
   */
  const [workspace, setWorkspace] = useState<StaffWorkspaceKind | null>(
    shouldOpenResendInvitation ? 'resend-invitation' : null,
  );
  const {
    setDirty: setWorkspaceDirty,
    pendingSwitch,
    requestSwitch,
    confirmPendingSwitch,
    cancelPendingSwitch,
  } = useWorkspaceGuard();

  const invitationForm = useForm({
    email: staff.email ?? '',
    primary_role_key: '',
    secondary_role_keys: [] as string[],
    change_reference: '',
    access_reason: '',
  });
  const resendInvitationForm = useForm({
    change_reference: '',
    access_reason: '',
  });
  const qualificationForm = useForm({
    award: '',
    field_of_study: '',
    institution: '',
    completion_year: '',
    verification_status: 'UNVERIFIED' as StaffQualification['verificationStatus'],
  });
  const [editingQualificationId, setEditingQualificationId] = useState<string | null>(null);
  const [accessAction, setAccessAction] = useState<{
    assignment: StaffAccessAssignment;
    action: 'suspend' | 'restore' | 'revoke';
  } | null>(null);
  const [accessActionReason, setAccessActionReason] = useState('');
  const [accessActionProcessing, setAccessActionProcessing] = useState(false);
  const [invitationAction, setInvitationAction] = useState<'revoke' | 'unlink' | null>(null);
  const [invitationActionReason, setInvitationActionReason] = useState('');
  const [invitationActionProcessing, setInvitationActionProcessing] = useState(false);
  const personForm = useForm({
    first_name: profile?.firstName ?? '',
    middle_name: profile?.middleName ?? '',
    last_name: profile?.lastName ?? '',
    date_of_birth: profile?.dateOfBirth ?? '',
    gender: profile?.gender ?? 'UNKNOWN',
    national_id: profile?.nationalId ?? '',
    phone: profile?.phone ?? '',
    email: profile?.email ?? '',
    physical_address: profile?.physicalAddress ?? '',
    photo: null as File | null,
  });

  useEffect(() => {
    const photo = personForm.data.photo;
    if (typeof File === 'undefined' || !(photo instanceof File) || typeof URL.createObjectURL !== 'function') {
      setPhotoPreviewUrl(null);
      return undefined;
    }

    const previewUrl = URL.createObjectURL(photo);
    setPhotoPreviewUrl(previewUrl);
    return () => URL.revokeObjectURL(previewUrl);
  }, [personForm.data.photo]);

  const openWorkspace = useCallback((next: StaffWorkspaceKind | null) => {
    if (next !== 'personal') personForm.reset();
    if (next !== 'invite') invitationForm.reset();
    if (next !== 'resend-invitation') resendInvitationForm.reset();
    setWorkspaceDirty(false);
    setWorkspace(next);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [setWorkspaceDirty]);

  const closeWorkspace = useCallback(() => {
    requestSwitch(() => openWorkspace(null));
  }, [requestSwitch, openWorkspace]);

  const selectMenuWorkspace = useCallback((kind: StaffWorkspaceKind) => {
    requestSwitch(() => openWorkspace(kind));
  }, [requestSwitch, openWorkspace]);

  function reinstateStaff(): void {
    requestSwitch(() => {
      router.post(routes.workspaces.hrStaffReinstate(staff.id), {}, { preserveScroll: true });
    });
  }

  function openAccessRequests(): void {
    router.visit(routes.workspaces.hrStaffAccessRequests);
  }

  function submitAccessAction(): void {
    if (accessAction === null || accessActionReason.trim().length < 8 || accessActionProcessing) return;
    setAccessActionProcessing(true);
    router.post(
      routes.workspaces.hrStaffAccessAssignment(staff.id, accessAction.assignment.id, accessAction.action),
      { reason: accessActionReason.trim() },
      {
        preserveScroll: true,
        onFinish: () => {
          setAccessActionProcessing(false);
          setAccessAction(null);
          setAccessActionReason('');
        },
      },
    );
  }

  // Dirty tracking for the controlled edit panels — reported only while the
  // matching workspace is open so a closed panel never blocks navigation.
  const personBaseline = JSON.stringify({
    first_name: profile?.firstName ?? '',
    middle_name: profile?.middleName ?? '',
    last_name: profile?.lastName ?? '',
    date_of_birth: profile?.dateOfBirth ?? '',
    gender: profile?.gender ?? 'UNKNOWN',
    national_id: profile?.nationalId ?? '',
    phone: profile?.phone ?? '',
    email: profile?.email ?? '',
    physical_address: profile?.physicalAddress ?? '',
  });
  useEffect(() => {
    if (workspace !== 'personal') return;
    const current = JSON.stringify({
      first_name: personForm.data.first_name,
      middle_name: personForm.data.middle_name,
      last_name: personForm.data.last_name,
      date_of_birth: personForm.data.date_of_birth,
      gender: personForm.data.gender,
      national_id: personForm.data.national_id,
      phone: personForm.data.phone,
      email: personForm.data.email,
      physical_address: personForm.data.physical_address,
    });
    setWorkspaceDirty(current !== personBaseline || personForm.data.photo !== null);
  }, [
    workspace,
    personForm.data,
    personBaseline,
    setWorkspaceDirty,
  ]);

  useEffect(() => {
    if (workspace !== 'invite') return;
    const dirty = invitationForm.data.email !== (staff.email ?? '')
      || invitationForm.data.primary_role_key !== ''
      || invitationForm.data.secondary_role_keys.length > 0
      || invitationForm.data.change_reference !== ''
      || invitationForm.data.access_reason !== '';
    setWorkspaceDirty(dirty);
  }, [workspace, invitationForm.data, staff.email, setWorkspaceDirty]);

  useEffect(() => {
    if (workspace !== 'resend-invitation') return;
    setWorkspaceDirty(
      resendInvitationForm.data.change_reference !== ''
      || resendInvitationForm.data.access_reason !== '',
    );
  }, [workspace, resendInvitationForm.data, setWorkspaceDirty]);

  function updatePerson(event: FormEvent): void {
    event.preventDefault();
    personForm.transform((data) => ({ ...data, _method: 'put' }));
    personForm.post(routes.workspaces.hrStaffPerson(staff.id), { preserveScroll: true, forceFormData: true });
  }

  function submitAction(
    event: FormEvent,
    url: string,
    field: string,
  ): void {
    event.preventDefault();
    const value = new FormData(event.currentTarget as HTMLFormElement).get(field);
    router.post(url, { [field]: String(value ?? '') }, { preserveScroll: true });
  }

  function linkAccount(event: FormEvent): void {
    event.preventDefault();
    const value = new FormData(event.currentTarget as HTMLFormElement).get('email');
    router.post(routes.workspaces.hrStaffLink(staff.id), { email: String(value ?? '') }, { preserveScroll: true });
  }

  function inviteAccount(event: FormEvent): void {
    event.preventDefault();
    invitationForm.post(routes.workspaces.hrStaffInviteAccount(staff.id), { preserveScroll: true });
  }

  function resendInvitation(event: FormEvent): void {
    event.preventDefault();
    resendInvitationForm.post(routes.workspaces.hrStaffResendInvitation(staff.id), { preserveScroll: true });
  }

  function submitInvitationAction(): void {
    if (invitationAction === null || invitationActionReason.trim().length < 8 || invitationActionProcessing) return;
    setInvitationActionProcessing(true);
    const url = invitationAction === 'revoke'
      ? routes.workspaces.hrStaffRevokeInvitation(staff.id)
      : routes.workspaces.hrStaffUnlinkAccount(staff.id);
    router.post(
      url,
      { reason: invitationActionReason.trim() },
      {
        preserveScroll: true,
        onFinish: () => {
          setInvitationActionProcessing(false);
          setInvitationAction(null);
          setInvitationActionReason('');
        },
      },
    );
  }

  function editQualification(qualification: StaffQualification): void {
    setEditingQualificationId(qualification.id);
    qualificationForm.setData({
      award: qualification.award,
      field_of_study: qualification.fieldOfStudy,
      institution: qualification.institution,
      completion_year: qualification.completionYear?.toString() ?? '',
      verification_status: qualification.verificationStatus,
    });
  }

  function submitQualification(event: FormEvent): void {
    event.preventDefault();
    const options = {
      preserveScroll: true,
      onSuccess: () => {
        qualificationForm.reset();
        setEditingQualificationId(null);
      },
    };
    if (editingQualificationId) {
      qualificationForm.put(
        routes.workspaces.hrStaffQualification(staff.id, editingQualificationId),
        options,
      );
      return;
    }
    qualificationForm.post(routes.workspaces.hrStaffQualifications(staff.id), options);
  }

  function terminateStaff(event: FormEvent): void {
    event.preventDefault();
    const formData = new FormData(event.currentTarget as HTMLFormElement);
    router.post(routes.workspaces.hrStaffTerminate(staff.id), {
      terminated_date: String(formData.get('terminated_date') ?? ''),
      reason: String(formData.get('reason') ?? ''),
    }, { preserveScroll: true });
  }

  const terminationBlockerMessages = [
    terminationBlockers.activeAssignments > 0
      ? `${terminationBlockers.activeAssignments} active assignment(s) must be ended`
      : null,
    terminationBlockers.openEmployments > 0
      ? `${terminationBlockers.openEmployments} active or suspended employment(s) must be ended`
      : null,
    terminationBlockers.futurePublishedShifts > 0
      ? `${terminationBlockers.futurePublishedShifts} future published shift(s) must be reassigned or cancelled`
      : null,
  ].filter((message): message is string => message !== null);

  const assignment = staffAssignmentLabel(staff);
  const assignmentNeedsReview = Boolean(staff.pendingAssignmentRequestId) && !staff.hasCurrentFacilityAssignment;
  const accountIsLinked = staff.linkedUserId !== null;
  const now = new Date();
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  const canEditPerson = canManagePerson && staff.hasCurrentFacilityAssignment;
  const canOnboard = canManageStaff && staff.hasCurrentFacilityAssignment && staff.status === 'PROSPECT';
  const canStartEmployment = canManageEmployment
    && staff.hasCurrentFacilityAssignment
    && staff.status === 'ACTIVE'
    && staff.employment?.status === 'DRAFT';
  const canSuspend = canManageStaff && staff.status === 'ACTIVE';
  const canReinstate = canManageStaff && staff.status === 'SUSPENDED';
  const canTerminate = canManageStaff && staff.status !== 'TERMINATED';

  function sectionLabel(): string {
    switch (selectedSection) {
      case 'personal': return 'Personal details';
      case 'employment': return 'Employment';
      case 'qualifications': return 'Qualifications';
      case 'access': return 'System access';
      default: return 'Staff Summary';
    }
  }

  return (
    <div className="patient-chart staff-profile">
      <main className="patient-chart__main hr-staff-profile">
        <StaffBanner
          staff={staff}
          photoUrl={photoPreviewUrl ?? staff.photoUrl}
          menu={(
            <StaffActionsMenu
              staff={staff}
              canManagePerson={canEditPerson}
              canManageEmployment={canManageEmployment}
              canManageStaff={canManageStaff}
              canLinkStaff={canLinkStaff}
              canInviteUser={canInviteUser}
              onWorkspace={selectMenuWorkspace}
              onReinstate={reinstateStaff}
            />
          )}
        />
        {(staff.pendingAssignmentRequestId || feedback) ? (
          <div className="staff-profile__notices">
            {staff.pendingAssignmentRequestId ? (
              <InlineNotification
                kind="warning"
                lowContrast
                title="Placement awaiting confirmation"
                subtitle={`Requested placement: ${staff.unitName ?? staff.departmentName ?? 'Facility-wide'}${staff.pendingAssignmentEffectiveFrom ? `, effective ${staff.pendingAssignmentEffectiveFrom}` : ''}. An authorized reviewer must confirm it before this becomes an active facility assignment.`}
                hideCloseButton
              />
            ) : null}
            {feedback}
          </div>
        ) : null}

        <div className="staff-profile__body">
          {selectedSection === 'overview' ? (
            <section className="staff-profile__section" aria-label="Staff Summary">
              <ProfileSectionHeader
                eyebrow="Staff summary"
                title="At a glance"
                description={`Assignment, employment, and account details for ${staff.name}.`}
              />

              <div className="staff-profile__summary-grid">
                <article className="afy-card staff-profile__card">
                  <div className="staff-profile__card-heading">
                    <span className="staff-profile__card-icon" aria-hidden="true"><Building size={20} /></span>
                    <div className="staff-profile__card-copy">
                      <p className="staff-profile__card-eyebrow">Assignment</p>
                      <h3 className="staff-profile__card-title">{staff.positionTitle ?? 'Position not assigned'}</h3>
                    </div>
                    <Tag
                      className="staff-profile__card-tag"
                      type={assignmentNeedsReview ? 'blue' : staff.hasCurrentFacilityAssignment ? 'green' : 'cool-gray'}
                    >
                      {assignmentNeedsReview ? 'Pending review' : staff.hasCurrentFacilityAssignment ? 'Active' : 'Not assigned'}
                    </Tag>
                  </div>
                  <p className="staff-profile__card-description">{assignment}</p>
                  <dl className="afy-facts">
                    <div className="afy-facts__item">
                      <dt>Department</dt>
                      <dd>{staff.departmentName ?? 'Not recorded'}</dd>
                    </div>
                    <div className="afy-facts__item">
                      <dt>Joined</dt>
                      <dd>{displayDate(staff.joinedDate, 'Not yet onboarded')}</dd>
                    </div>
                  </dl>
                </article>

                <article className="afy-card staff-profile__card">
                  <div className="staff-profile__card-heading">
                    <span className="staff-profile__card-icon" aria-hidden="true"><Calendar size={20} /></span>
                    <div className="staff-profile__card-copy">
                      <p className="staff-profile__card-eyebrow">Employment</p>
                      <h3 className="staff-profile__card-title">
                        {!canManageEmployment
                          ? 'Employment'
                          : staff.employment
                            ? `${readableLabel(staff.employment.status)} employment`
                            : 'No employment record'}
                      </h3>
                    </div>
                    {canManageEmployment && staff.employment ? (
                      <Tag
                        className="staff-profile__card-tag"
                        type={employmentStatusTagType(staff.employment.status)}
                      >
                        {readableLabel(staff.employment.status)}
                      </Tag>
                    ) : null}
                  </div>
                  {!canManageEmployment ? (
                    <p className="staff-profile__card-description">Details not available for your access level</p>
                  ) : staff.employment ? (
                    <>
                      <p className="staff-profile__card-description">
                        {readableLabel(staff.employment.contractType)} · {staff.employment.fte} FTE
                      </p>
                      <dl className="afy-facts">
                        <div className="afy-facts__item">
                          <dt>Start date</dt>
                          <dd>{displayDate(staff.employment.startDate, 'Not set')}</dd>
                        </div>
                        {staff.employment.probationEndDate ? (
                          <div className="afy-facts__item">
                            <dt>Probation ends</dt>
                            <dd>{displayDate(staff.employment.probationEndDate)}</dd>
                          </div>
                        ) : staff.employment.endDate ? (
                          <div className="afy-facts__item">
                            <dt>End date</dt>
                            <dd>{displayDate(staff.employment.endDate)}</dd>
                          </div>
                        ) : null}
                      </dl>
                    </>
                  ) : (
                    <p className="staff-profile__card-description">
                      Employment is created when onboarding is completed.
                    </p>
                  )}
                </article>

                <article className="afy-card staff-profile__card">
                  <div className="staff-profile__card-heading">
                    <span className="staff-profile__card-icon" aria-hidden="true"><Locked size={20} /></span>
                    <div className="staff-profile__card-copy">
                      <p className="staff-profile__card-eyebrow">Account</p>
                      <h3 className="staff-profile__card-title">
                        {accountIsLinked ? staff.linkedUserEmail ?? 'AfyaNova account' : 'No account linked'}
                      </h3>
                    </div>
                    <Tag className="staff-profile__card-tag" type={accountIsLinked ? 'green' : 'cool-gray'}>
                      {accountIsLinked ? 'Linked' : 'Not linked'}
                    </Tag>
                  </div>
                  <p className="staff-profile__card-description">
                    {accountIsLinked
                      ? 'The account is linked. Facility roles control what this person can do in AfyaNova.'
                      : 'Invite a new account or link an existing verified account.'}
                  </p>
                  <dl className="afy-facts">
                    <div className="afy-facts__item">
                      <dt>Facility roles</dt>
                      <dd>{accountIsLinked ? `${accessAssignments.length} assigned` : 'Not applicable'}</dd>
                    </div>
                    <div className="afy-facts__item">
                      <dt>Setup invitation</dt>
                      <dd>{staff.setupInvitation ? readableLabel(staff.setupInvitation.status) : 'None'}</dd>
                    </div>
                  </dl>
                </article>
              </div>

              <article className="afy-card staff-profile__card staff-profile__lifecycle">
                <div className="staff-profile__card-heading">
                  <span className="staff-profile__card-icon" aria-hidden="true"><UserProfile size={20} /></span>
                  <div className="staff-profile__card-copy">
                    <p className="staff-profile__card-eyebrow">Lifecycle</p>
                    <h3 className="staff-profile__card-title">Staff lifecycle</h3>
                  </div>
                  <Tag className="staff-profile__card-tag" type={staffStatusTagType(staff.status)}>
                    {staffStatusLabel(staff.status)}
                  </Tag>
                </div>
                <p className="staff-profile__card-description">{lifecycleDescription(staff.status)}</p>
                <div className="staff-profile__fact-columns">
                  <dl className="afy-facts">
                    <div className="afy-facts__item">
                      <dt>Joined</dt>
                      <dd>{displayDate(staff.joinedDate, 'Not yet onboarded')}</dd>
                    </div>
                    <div className="afy-facts__item">
                      <dt>AfyaNova account</dt>
                      <dd>{staff.linkedUserEmail ?? 'No account linked'}</dd>
                    </div>
                  </dl>
                  <dl className="afy-facts">
                    {staff.status === 'SUSPENDED' && staff.suspensionReason ? (
                      <div className="afy-facts__item">
                        <dt>Suspension reason</dt>
                        <dd>{staff.suspensionReason}</dd>
                      </div>
                    ) : null}
                    {staff.status === 'TERMINATED' ? (
                      <div className="afy-facts__item">
                        <dt>Termination</dt>
                        <dd>{`${displayDate(staff.terminatedDate, 'Date unavailable')}${staff.terminationReason ? ` · ${staff.terminationReason}` : ''}`}</dd>
                      </div>
                    ) : null}
                    {staff.status !== 'SUSPENDED' && staff.status !== 'TERMINATED' ? (
                      <div className="afy-facts__item">
                        <dt>Facility assignment</dt>
                        <dd>{assignment}</dd>
                      </div>
                    ) : null}
                  </dl>
                </div>
                {(canOnboard || canStartEmployment || canSuspend || canReinstate || canTerminate) ? (
                  <div className="staff-profile__card-footer staff-profile__lifecycle-actions">
                    {canOnboard ? (
                      <Button kind="ghost" size="sm" type="button" onClick={() => selectMenuWorkspace('onboard')}>
                        Complete onboarding
                      </Button>
                    ) : null}
                    {canStartEmployment ? (
                      <Button kind="ghost" size="sm" type="button" onClick={() => selectMenuWorkspace('employment-start')}>
                        Start employment
                      </Button>
                    ) : null}
                    {canSuspend ? (
                      <Button kind="ghost" size="sm" type="button" onClick={() => selectMenuWorkspace('suspend')}>
                        Suspend staff
                      </Button>
                    ) : null}
                    {canReinstate ? (
                      <Button kind="ghost" size="sm" type="button" onClick={reinstateStaff}>
                        Reinstate staff
                      </Button>
                    ) : null}
                    {canTerminate ? (
                      <Button kind="ghost" size="sm" type="button" onClick={() => selectMenuWorkspace('terminate')}>
                        Terminate staff
                      </Button>
                    ) : null}
                  </div>
                ) : null}
              </article>
            </section>
          ) : null}

          {selectedSection === 'personal' ? (
            <section className="staff-profile__section" aria-label="Personal details">
              <ProfileSectionHeader
                eyebrow="Personal"
                title="Personal details"
                description="Identity and contact information held on this staff record."
                actions={canEditPerson && profile ? (
                  <Button
                    kind="ghost"
                    size="sm"
                    type="button"
                    renderIcon={Edit}
                    onClick={() => selectMenuWorkspace('personal')}
                  >
                    Edit personal details
                  </Button>
                ) : undefined}
              />

              {canEditPerson && profile ? (
                <div className="staff-profile__detail-grid">
                  <article className="afy-card staff-profile__card">
                    <div className="staff-profile__card-heading">
                      <span className="staff-profile__card-icon" aria-hidden="true"><UserProfile size={20} /></span>
                      <div className="staff-profile__card-copy">
                        <p className="staff-profile__card-eyebrow">Identity</p>
                        <h3 className="staff-profile__card-title">Personal information</h3>
                      </div>
                    </div>
                    <dl className="afy-facts">
                      <div className="afy-facts__item"><dt>First name</dt><dd>{profile.firstName}</dd></div>
                      <div className="afy-facts__item"><dt>Middle name</dt><dd>{profile.middleName ?? '—'}</dd></div>
                      <div className="afy-facts__item"><dt>Last name</dt><dd>{profile.lastName}</dd></div>
                      <div className="afy-facts__item"><dt>Date of birth</dt><dd>{displayDate(profile.dateOfBirth)}</dd></div>
                      <div className="afy-facts__item"><dt>Gender</dt><dd>{readableLabel(profile.gender)}</dd></div>
                      <div className="afy-facts__item"><dt>National ID</dt><dd>{profile.nationalId ?? '—'}</dd></div>
                    </dl>
                  </article>

                  <article className="afy-card staff-profile__card">
                    <div className="staff-profile__card-heading">
                      <span className="staff-profile__card-icon" aria-hidden="true"><UserFollow size={20} /></span>
                      <div className="staff-profile__card-copy">
                        <p className="staff-profile__card-eyebrow">Contact</p>
                        <h3 className="staff-profile__card-title">Contact information</h3>
                      </div>
                    </div>
                    <dl className="afy-facts">
                      <div className="afy-facts__item"><dt>Phone</dt><dd>{profile.phone ?? '—'}</dd></div>
                      <div className="afy-facts__item"><dt>Contact email</dt><dd>{profile.email ?? '—'}</dd></div>
                      <div className="afy-facts__item"><dt>Physical address</dt><dd>{profile.physicalAddress ?? '—'}</dd></div>
                    </dl>
                  </article>
                </div>
              ) : (
                <ProfileEmptyState
                  icon={<Locked size={20} />}
                  title="Personal details unavailable"
                  description="You do not have permission to manage this staff member’s personal details."
                />
              )}
            </section>
          ) : null}

          {selectedSection === 'employment' ? (
            <section className="staff-profile__section" aria-label="Employment">
              <ProfileSectionHeader
                eyebrow="Employment"
                title="Employment record"
                description="Contract, working pattern, and employment dates for this staff member."
              />

              {staff.status === 'PROSPECT' && canManageStaff ? (
                <article className="afy-card staff-profile__callout">
                  <span className="staff-profile__callout-icon" aria-hidden="true"><UserFollow size={20} /></span>
                  <div className="staff-profile__callout-copy">
                    <h3 className="staff-profile__callout-title">Complete onboarding</h3>
                    <p className="staff-profile__callout-description">
                      Onboarding is available after the facility assignment is active. Completing it records the
                      joined date and starts a due draft employment when you also have permission to manage
                      employment. Future-dated employment remains a draft for the separate start action.
                    </p>
                  </div>
                  <Button
                    kind="secondary"
                    size="sm"
                    type="button"
                    disabled={!canOnboard}
                    onClick={() => selectMenuWorkspace('onboard')}
                  >
                    Complete onboarding
                  </Button>
                </article>
              ) : null}

              {canManageEmployment ? (
                staff.employment ? (
                  <article className="afy-card staff-profile__card">
                    <div className="staff-profile__card-heading">
                      <span className="staff-profile__card-icon" aria-hidden="true"><Calendar size={20} /></span>
                      <div className="staff-profile__card-copy">
                        <p className="staff-profile__card-eyebrow">Employment</p>
                        <h3 className="staff-profile__card-title">{`${readableLabel(staff.employment.status)} employment`}</h3>
                      </div>
                      <Tag className="staff-profile__card-tag" type={employmentStatusTagType(staff.employment.status)}>
                        {readableLabel(staff.employment.status)}
                      </Tag>
                    </div>
                    <p className="staff-profile__card-description">
                      {readableLabel(staff.employment.contractType)} · {staff.employment.fte} FTE
                    </p>
                    <div className="staff-profile__fact-columns">
                      <dl className="afy-facts">
                        <div className="afy-facts__item">
                          <dt>Position</dt>
                          <dd>{staff.positionTitle ?? 'Not assigned'}</dd>
                        </div>
                        <div className="afy-facts__item">
                          <dt>Contract type</dt>
                          <dd>{readableLabel(staff.employment.contractType)}</dd>
                        </div>
                        <div className="afy-facts__item">
                          <dt>Full-time equivalent</dt>
                          <dd>{staff.employment.fte}</dd>
                        </div>
                      </dl>
                      <dl className="afy-facts">
                        <div className="afy-facts__item">
                          <dt>Start date</dt>
                          <dd>{displayDate(staff.employment.startDate, 'Not set')}</dd>
                        </div>
                        {staff.employment.endDate ? (
                          <div className="afy-facts__item">
                            <dt>End date</dt>
                            <dd>{displayDate(staff.employment.endDate)}</dd>
                          </div>
                        ) : null}
                        {staff.employment.probationEndDate ? (
                          <div className="afy-facts__item">
                            <dt>Probation ends</dt>
                            <dd>{displayDate(staff.employment.probationEndDate)}</dd>
                          </div>
                        ) : null}
                      </dl>
                    </div>
                    {staff.employment.terms ? (
                      <div className="staff-profile__note-block">
                        <h4>Terms</h4>
                        <p>{staff.employment.terms}</p>
                      </div>
                    ) : null}
                    {canStartEmployment ? (
                      <div className="staff-profile__action-note">
                        <p className="staff-profile__note">
                          Onboarding starts a due draft automatically. Use this action only for a future-dated
                          contract or when onboarding was completed without employment permission.
                        </p>
                        <Button kind="ghost" size="sm" type="button" onClick={() => selectMenuWorkspace('employment-start')}>
                          Start employment
                        </Button>
                      </div>
                    ) : null}
                  </article>
                ) : (
                  <ProfileEmptyState
                    icon={<Calendar size={20} />}
                    title="No employment record"
                    description="Employment is created when onboarding is completed."
                  />
                )
              ) : (
                <ProfileEmptyState
                  icon={<Locked size={20} />}
                  title="Employment details unavailable"
                  description="Details not available for your access level"
                />
              )}
            </section>
          ) : null}

          {selectedSection === 'qualifications' ? (
            <section className="staff-profile__section" aria-label="Qualifications">
              <ProfileSectionHeader
                eyebrow="Qualifications"
                title="Education & training"
                description="Academic awards, professional training, and verification status."
                actions={canReadQualifications && qualifications.length > 0 ? (
                  <Tag type="gray">{qualifications.length} recorded</Tag>
                ) : undefined}
              />

              {!canReadQualifications ? (
                <ProfileEmptyState
                  icon={<Locked size={20} />}
                  title="Qualifications unavailable"
                  description="You do not have permission to view this staff member’s qualifications."
                />
              ) : (
                <>
                  {qualifications.length === 0 ? (
                    <ProfileEmptyState
                      icon={<Document size={20} />}
                      title="No qualifications recorded"
                      description="Record education or training separately from the staff member’s cadre, position, and professional registration."
                    />
                  ) : (
                    <div className="staff-profile__qualification-grid">
                      {qualifications.map((qualification) => (
                        <article className="afy-card staff-profile__card" key={qualification.id}>
                          <div className="staff-profile__card-heading">
                            <span className="staff-profile__card-icon" aria-hidden="true"><Document size={20} /></span>
                            <div className="staff-profile__card-copy">
                              <p className="staff-profile__card-eyebrow">Qualification</p>
                              <h3 className="staff-profile__card-title">{qualification.award}</h3>
                            </div>
                            <Tag
                              className="staff-profile__card-tag"
                              type={qualification.verificationStatus === 'VERIFIED' ? 'green' : 'cool-gray'}
                            >
                              {readableLabel(qualification.verificationStatus)}
                            </Tag>
                          </div>
                          <p className="staff-profile__card-description">{qualification.fieldOfStudy}</p>
                          <dl className="afy-facts">
                            <div className="afy-facts__item">
                              <dt>Institution</dt>
                              <dd>{qualification.institution}</dd>
                            </div>
                            <div className="afy-facts__item">
                              <dt>Completion year</dt>
                              <dd>{qualification.completionYear ?? 'Not recorded'}</dd>
                            </div>
                          </dl>
                          {canManageStaff ? (
                            <div className="staff-profile__card-footer">
                              <Button
                                kind="ghost"
                                size="sm"
                                type="button"
                                renderIcon={Edit}
                                onClick={() => editQualification(qualification)}
                              >
                                Edit qualification
                              </Button>
                            </div>
                          ) : null}
                        </article>
                      ))}
                    </div>
                  )}

                  {canManageStaff ? (
                    <article className="afy-card staff-profile__card staff-profile__form-card">
                      <div className="staff-profile__card-heading">
                        <span className="staff-profile__card-icon" aria-hidden="true"><Document size={20} /></span>
                        <div className="staff-profile__card-copy">
                          <p className="staff-profile__card-eyebrow">Record</p>
                          <h3 className="staff-profile__card-title">
                            {editingQualificationId ? 'Edit qualification' : 'Add qualification'}
                          </h3>
                        </div>
                      </div>
                      <p className="staff-profile__card-description">
                        Record education or training separately from the staff member’s cadre, position, and professional registration.
                      </p>
                      <form onSubmit={submitQualification}>
                        <div className="facility-staff__create-grid">
                          <TextInput
                            id="staff-qualification-award"
                            labelText="Qualification or award"
                            value={qualificationForm.data.award}
                            onChange={(event) => qualificationForm.setData('award', event.currentTarget.value)}
                            invalid={Boolean(qualificationForm.errors.award)}
                            invalidText={qualificationForm.errors.award}
                            required
                          />
                          <TextInput
                            id="staff-qualification-field"
                            labelText="Field of study"
                            value={qualificationForm.data.field_of_study}
                            onChange={(event) => qualificationForm.setData('field_of_study', event.currentTarget.value)}
                            invalid={Boolean(qualificationForm.errors.field_of_study)}
                            invalidText={qualificationForm.errors.field_of_study}
                            required
                          />
                          <TextInput
                            id="staff-qualification-institution"
                            labelText="Institution"
                            value={qualificationForm.data.institution}
                            onChange={(event) => qualificationForm.setData('institution', event.currentTarget.value)}
                            invalid={Boolean(qualificationForm.errors.institution)}
                            invalidText={qualificationForm.errors.institution}
                            required
                          />
                          <TextInput
                            id="staff-qualification-year"
                            type="number"
                            labelText="Completion year (optional)"
                            min="1900"
                            max={new Date().getFullYear()}
                            value={qualificationForm.data.completion_year}
                            onChange={(event) => qualificationForm.setData('completion_year', event.currentTarget.value)}
                            invalid={Boolean(qualificationForm.errors.completion_year)}
                            invalidText={qualificationForm.errors.completion_year}
                          />
                          <Select
                            id="staff-qualification-verification"
                            labelText="Verification"
                            value={qualificationForm.data.verification_status}
                            onChange={(event) => qualificationForm.setData(
                              'verification_status',
                              event.currentTarget.value as StaffQualification['verificationStatus'],
                            )}
                            invalid={Boolean(qualificationForm.errors.verification_status)}
                            invalidText={qualificationForm.errors.verification_status}
                          >
                            <SelectItem value="UNVERIFIED" text="Not verified" />
                            <SelectItem value="VERIFIED" text="Verified" />
                          </Select>
                        </div>
                        <div className="staff-profile__form-actions">
                          <Button type="submit" disabled={qualificationForm.processing}>
                            {qualificationForm.processing ? 'Saving…' : editingQualificationId ? 'Save changes' : 'Add qualification'}
                          </Button>
                          {editingQualificationId ? (
                            <Button
                              kind="tertiary"
                              type="button"
                              onClick={() => {
                                qualificationForm.reset();
                                setEditingQualificationId(null);
                              }}
                            >
                              Cancel
                            </Button>
                          ) : null}
                        </div>
                      </form>
                    </article>
                  ) : (
                    <p className="staff-profile__note">You do not have permission to manage qualifications.</p>
                  )}
                </>
              )}
            </section>
          ) : null}

          {selectedSection === 'access' ? (
            <section className="staff-profile__section" aria-label="System access">
              <ProfileSectionHeader
                eyebrow="Access"
                title="System access"
                description="AfyaNova account link, setup state, facility roles, and audit history."
              />

              {accountIsLinked ? (
                <>
                  <article className="afy-card staff-profile__card">
                    <div className="staff-profile__card-heading">
                      <span className="staff-profile__card-icon" aria-hidden="true"><UserProfile size={20} /></span>
                      <div className="staff-profile__card-copy">
                        <p className="staff-profile__card-eyebrow">Account</p>
                        <h3 className="staff-profile__card-title">AfyaNova account</h3>
                      </div>
                      <Tag className="staff-profile__card-tag" type="green">Linked</Tag>
                    </div>

                    <div className="staff-profile__account-summary">
                      <StaffIdentityBlock staff={staff} />
                      <div className="staff-profile__account-email">
                        <span className="staff-profile__account-label">Sign-in email</span>
                        <span className="staff-profile__account-value">
                          {staff.linkedUserEmail ?? 'Account details unavailable'}
                        </span>
                      </div>
                    </div>
                    <p className="staff-profile__card-description">
                      This account is linked to the staff record. Linking alone does not grant facility access.
                    </p>

                    {pendingInvitationAccessRequests.length > 0 ? (
                      <div className="staff-profile__notices">
                        <InlineNotification
                          kind="warning"
                          lowContrast
                          title="Facility access is awaiting review"
                          subtitle={`${pendingInvitationAccessRequests.map((accessRequest) => accessRequest.roleLabel).join(', ')} requested. Review the request in Role approvals.`}
                          hideCloseButton
                        />
                        <div>
                          <Button kind="ghost" size="sm" type="button" onClick={openAccessRequests}>
                            Open access requests
                          </Button>
                        </div>
                      </div>
                    ) : null}

                    {staff.setupInvitation ? (
                      <div className="staff-profile__notices">
                        {staff.setupInvitation.status === 'REVOKED' ? (
                          <InlineNotification
                            kind="warning"
                            lowContrast
                            title="Setup invitation revoked"
                            subtitle="The link can no longer be used. Unlink the unactivated account before inviting a different email."
                            hideCloseButton
                          />
                        ) : staff.setupInvitation.status === 'SUPERSEDED' ? (
                          <InlineNotification
                            kind="info"
                            lowContrast
                            title="Previous setup link replaced"
                            subtitle="A newer invitation was sent. Only the latest link can be used."
                            hideCloseButton
                          />
                        ) : staff.setupInvitation.expired ? (
                          <InlineNotification
                            kind="warning"
                            lowContrast
                            title="Setup invitation expired"
                            subtitle="Send a fresh email verification link to restore account setup."
                            hideCloseButton
                          />
                        ) : staff.setupInvitation.status === 'CONSUMED' ? (
                          <InlineNotification
                            kind="success"
                            lowContrast
                            title="Account setup completed"
                            subtitle="The invited user accepted the setup link and verified their email."
                            hideCloseButton
                          />
                        ) : (
                          <InlineNotification
                            kind="info"
                            lowContrast
                            title="Setup invitation pending"
                            subtitle={`The link was sent ${staff.setupInvitation.sentAt
                              ? new Date(staff.setupInvitation.sentAt).toLocaleString()
                              : 'recently'} and expires ${new Date(staff.setupInvitation.expiresAt).toLocaleString()}.`}
                            hideCloseButton
                          />
                        )}
                        {staff.setupInvitation.deliveryStatus === 'FAILED' ? (
                          <InlineNotification
                            kind="error"
                            lowContrast
                            title="Invitation email delivery failed"
                            subtitle="The account exists, but the email did not send. Resend the invitation now."
                            hideCloseButton
                          />
                        ) : null}
                        <div className="staff-profile__card-footer">
                          {canResendInvitation && staff.setupInvitation.canResend ? (
                            <Button
                              kind="ghost"
                              size="sm"
                              type="button"
                              onClick={() => selectMenuWorkspace('resend-invitation')}
                            >
                              Resend invitation
                            </Button>
                          ) : null}
                          {canRevokeInvitation && staff.setupInvitation.canRevoke ? (
                            <Button
                              kind="danger--tertiary"
                              size="sm"
                              type="button"
                              onClick={() => {
                                setInvitationAction('revoke');
                                setInvitationActionReason('');
                              }}
                            >
                              Revoke invitation
                            </Button>
                          ) : null}
                          {canUnlinkAccount ? (
                            <Button
                              kind="danger--ghost"
                              size="sm"
                              type="button"
                              onClick={() => {
                                setInvitationAction('unlink');
                                setInvitationActionReason('');
                              }}
                            >
                              Unlink unactivated account
                            </Button>
                          ) : null}
                        </div>
                        {staff.setupInvitation.lastSentByName ? (
                          <p className="staff-profile__note">
                            Last sent by {staff.setupInvitation.lastSentByName}.
                          </p>
                        ) : null}
                      </div>
                    ) : null}
                  </article>

                  <section className="afy-card staff-profile__card" aria-label="Assigned facility roles">
                    <div className="staff-profile__card-heading">
                      <span className="staff-profile__card-icon" aria-hidden="true"><UserMultiple size={20} /></span>
                      <div className="staff-profile__card-copy">
                        <p className="staff-profile__card-eyebrow">Facility access</p>
                        <h3 className="staff-profile__card-title">Assigned roles</h3>
                      </div>
                      <Tag className="staff-profile__card-tag" type="gray">{accessAssignments.length}</Tag>
                    </div>

                    {accessAssignments.length === 0 ? (
                      <p className="staff-profile__muted">No facility roles are assigned to this account.</p>
                    ) : (
                      <ul className="staff-profile__role-list">
                        {accessAssignments.map((assignment) => (
                          <li className="staff-profile__role-row" key={assignment.id}>
                            <div className="staff-profile__role-main">
                              <div className="staff-profile__role-title">
                                <strong>{assignment.roleName}</strong>
                                <Tag type={accessAssignmentTagType(assignment)}>
                                  {accessAssignmentStatus(assignment)}
                                </Tag>
                              </div>
                              <p className="staff-profile__role-meta">
                                {assignment.effectiveFrom ? `From ${displayDate(assignment.effectiveFrom)}` : 'Effective immediately'}
                                {assignment.effectiveTo ? ` · Until ${displayDate(assignment.effectiveTo)}` : ''}
                                {assignment.scope === 'ORGANIZATION' ? ' · Organization-wide' : ''}
                                {assignment.isLifecycleSuspended ? ' · Managed by staff suspension' : ''}
                              </p>
                            </div>
                            {canManageStaffAccess && assignment.canManageIndividually ? (
                              <div className="staff-profile__role-actions">
                                {assignment.status === 'ACTIVE' && !assignment.isLifecycleSuspended ? (
                                  <Button
                                    kind="tertiary"
                                    size="sm"
                                    type="button"
                                    onClick={() => {
                                      setAccessAction({ assignment, action: 'suspend' });
                                      setAccessActionReason('');
                                    }}
                                  >
                                    Suspend role
                                  </Button>
                                ) : null}
                                {assignment.status === 'SUSPENDED' && !assignment.isLifecycleSuspended ? (
                                  <Button
                                    kind="tertiary"
                                    size="sm"
                                    type="button"
                                    onClick={() => {
                                      setAccessAction({ assignment, action: 'restore' });
                                      setAccessActionReason('');
                                    }}
                                  >
                                    Restore role
                                  </Button>
                                ) : null}
                                {assignment.status === 'ACTIVE' || assignment.status === 'SUSPENDED' ? (
                                  <Button
                                    kind="danger--tertiary"
                                    size="sm"
                                    type="button"
                                    onClick={() => {
                                      setAccessAction({ assignment, action: 'revoke' });
                                      setAccessActionReason('');
                                    }}
                                  >
                                    Revoke role
                                  </Button>
                                ) : null}
                              </div>
                            ) : null}
                          </li>
                        ))}
                      </ul>
                    )}
                  </section>

                  {(canReadAccessHistory || invitationHistory.length > 0) ? (
                    <div className="staff-profile__history-grid">
                      {canReadAccessHistory ? (
                        <section className="afy-card staff-profile__card" aria-label="Access change history">
                          <div className="staff-profile__card-heading">
                            <span className="staff-profile__card-icon" aria-hidden="true"><Time size={20} /></span>
                            <div className="staff-profile__card-copy">
                              <p className="staff-profile__card-eyebrow">Audit trail</p>
                              <h3 className="staff-profile__card-title">Access change history</h3>
                            </div>
                            <Tag className="staff-profile__card-tag" type="gray">{accessHistory.length}</Tag>
                          </div>
                          {accessHistory.length === 0 ? (
                            <p className="staff-profile__muted">
                              No access changes are recorded for this account at this facility.
                            </p>
                          ) : (
                            <ul className="staff-profile__timeline">
                              {accessHistory.map((entry, index) => (
                                <li
                                  className="staff-profile__timeline-item"
                                  key={`${entry.action}-${entry.role}-${entry.occurredAt}-${index}`}
                                >
                                  <span className="staff-profile__timeline-dot" aria-hidden="true" />
                                  <div className="staff-profile__timeline-copy">
                                    <p className="staff-profile__timeline-title">
                                      <strong>{assignmentRoleLabel(entry.role)}</strong>
                                      {' · '}{readableLabel(entry.action.replaceAll('.', ' '))}
                                    </p>
                                    <p className="staff-profile__timeline-meta">
                                      {new Date(entry.occurredAt).toLocaleString()} · by {entry.actorName}
                                    </p>
                                    {entry.reason ? (
                                      <p className="staff-profile__timeline-reason">{entry.reason}</p>
                                    ) : null}
                                  </div>
                                </li>
                              ))}
                            </ul>
                          )}
                        </section>
                      ) : null}

                      {invitationHistory.length > 0 ? (
                        <section className="afy-card staff-profile__card" aria-label="Invitation history">
                          <div className="staff-profile__card-heading">
                            <span className="staff-profile__card-icon" aria-hidden="true"><Document size={20} /></span>
                            <div className="staff-profile__card-copy">
                              <p className="staff-profile__card-eyebrow">Audit trail</p>
                              <h3 className="staff-profile__card-title">Invitation history</h3>
                            </div>
                            <Tag className="staff-profile__card-tag" type="gray">{invitationHistory.length}</Tag>
                          </div>
                          <ul className="staff-profile__timeline">
                            {invitationHistory.map((entry) => (
                              <li className="staff-profile__timeline-item" key={entry.id}>
                                <span className="staff-profile__timeline-dot" aria-hidden="true" />
                                <div className="staff-profile__timeline-copy">
                                  <p className="staff-profile__timeline-title">
                                    <strong>{entry.eventLabel}</strong>
                                  </p>
                                  <p className="staff-profile__timeline-meta">
                                    {new Date(entry.occurredAt).toLocaleString()} · by {entry.actorName}
                                  </p>
                                  {entry.reason ? (
                                    <p className="staff-profile__timeline-reason">{entry.reason}</p>
                                  ) : null}
                                </div>
                              </li>
                            ))}
                          </ul>
                        </section>
                      ) : null}
                    </div>
                  ) : null}
                </>
              ) : (
                <article className="afy-card staff-profile__card">
                  <div className="staff-profile__card-heading">
                    <span className="staff-profile__card-icon" aria-hidden="true"><UserProfile size={20} /></span>
                    <div className="staff-profile__card-copy">
                      <p className="staff-profile__card-eyebrow">Account</p>
                      <h3 className="staff-profile__card-title">No account linked</h3>
                    </div>
                    <Tag className="staff-profile__card-tag" type="cool-gray">Not linked</Tag>
                  </div>
                  <StaffIdentityBlock staff={staff} />
                  <p className="staff-profile__card-description">
                    Invite a new account or link an existing one — it attaches to this staff record automatically.
                  </p>
                  <div className="staff-profile__card-footer">
                    {canInviteUser ? (
                      <Button kind="secondary" size="sm" type="button" onClick={() => selectMenuWorkspace('invite')}>
                        Invite account
                      </Button>
                    ) : null}
                    {canLinkStaff ? (
                      <Button kind="tertiary" size="sm" type="button" onClick={() => selectMenuWorkspace('link')}>
                        Link existing account
                      </Button>
                    ) : null}
                    {!canInviteUser && !canLinkStaff ? (
                      <p className="staff-profile__note">No account is linked.</p>
                    ) : null}
                  </div>
                </article>
              )}
            </section>
          ) : null}

          {selectedSection !== 'overview' && selectedSection !== 'personal'
            && selectedSection !== 'employment' && selectedSection !== 'qualifications'
            && selectedSection !== 'access' ? (
              <section className="staff-profile__section" aria-label={sectionLabel()}>
                <ProfileEmptyState
                  icon={<Document size={20} />}
                  title="Section unavailable"
                  description="This profile section could not be loaded."
                />
              </section>
          ) : null}
        </div>

        <Modal
          open={accessAction !== null}
          modalHeading={accessAction === null
            ? 'Manage facility role'
            : `${readableLabel(accessAction.action)} ${assignmentRoleLabel(accessAction.assignment.role)}`}
          primaryButtonText={accessActionProcessing ? 'Saving…' : 'Confirm'}
          secondaryButtonText="Cancel"
          primaryButtonDisabled={accessActionProcessing || accessActionReason.trim().length < 8}
          danger={accessAction?.action === 'revoke'}
          onRequestClose={() => {
            if (!accessActionProcessing) setAccessAction(null);
          }}
          onRequestSubmit={submitAccessAction}
        >
          <p>
            {accessAction?.action === 'revoke'
              ? 'Revocation permanently removes this role assignment. It cannot be restored; a new access request will be required.'
              : accessAction?.action === 'suspend'
                ? 'Suspending this role blocks access through this role while leaving other assigned roles unchanged.'
                : 'Restoring this role re-enables access through this role.'}
          </p>
          <TextArea
            id="staff-access-action-reason"
            labelText="Reason (at least 8 characters)"
            value={accessActionReason}
            maxLength={1000}
            rows={4}
            onChange={(event) => setAccessActionReason(event.currentTarget.value)}
          />
        </Modal>

        <Modal
          open={invitationAction !== null}
          modalHeading={invitationAction === 'unlink' ? 'Unlink unactivated account' : 'Revoke setup invitation'}
          primaryButtonText={invitationActionProcessing
            ? 'Saving…'
            : invitationAction === 'unlink' ? 'Unlink account' : 'Revoke invitation'}
          secondaryButtonText="Cancel"
          primaryButtonDisabled={invitationActionProcessing || invitationActionReason.trim().length < 8}
          danger={invitationAction !== null}
          onRequestClose={() => {
            if (!invitationActionProcessing) setInvitationAction(null);
          }}
          onRequestSubmit={submitInvitationAction}
        >
          <p>
            {invitationAction === 'unlink'
              ? 'This removes the link between the staff record and the unactivated account. It does not delete the staff record, and it does not grant access. You can then invite the correct email or link an existing verified account.'
              : 'Revoking invalidates the current setup link. It does not delete the staff record or the unactivated account. Use unlink if the email itself was wrong.'}
          </p>
          <TextArea
            id="staff-invitation-action-reason"
            labelText="Reason for invitation action (at least 8 characters)"
            value={invitationActionReason}
            maxLength={500}
            rows={4}
            onChange={(event) => setInvitationActionReason(event.currentTarget.value)}
          />
        </Modal>
      </main>

      {workspace === 'personal' && canEditPerson && profile ? (
        <DockedPanel
          key={`personal:${staff.id}`}
          heading="Edit personal details"
          primaryLabel={personForm.processing ? 'Saving staff details…' : 'Save staff details'}
          primaryDisabled={personForm.processing}
          secondaryLabel="Cancel"
          onClose={closeWorkspace}
          onSubmit={updatePerson}
        >
          <FormErrors
            errors={personForm.errors}
            fields={[
              'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender',
              'national_id', 'phone', 'email', 'physical_address', 'photo',
            ]}
          />
          <TextInput id="edit-first" labelText="First name" value={personForm.data.first_name}
            onChange={(event) => personForm.setData('first_name', event.currentTarget.value)} required />
          <TextInput id="edit-middle" labelText="Middle name" value={personForm.data.middle_name}
            onChange={(event) => personForm.setData('middle_name', event.currentTarget.value)} />
          <TextInput id="edit-last" labelText="Last name" value={personForm.data.last_name}
            onChange={(event) => personForm.setData('last_name', event.currentTarget.value)} required />
          <TextInput id="edit-dob" type="date" labelText="Date of birth" value={personForm.data.date_of_birth}
            onChange={(event) => personForm.setData('date_of_birth', event.currentTarget.value)} required />
          <Select id="edit-gender" labelText="Gender" value={personForm.data.gender}
            onChange={(event) => personForm.setData('gender', event.currentTarget.value)}>
            <SelectItem value="UNKNOWN" text="Prefer not to say" />
            <SelectItem value="FEMALE" text="Female" />
            <SelectItem value="MALE" text="Male" />
            <SelectItem value="OTHER" text="Other" />
          </Select>
          <TextInput id="edit-national-id" labelText="National ID" value={personForm.data.national_id}
            onChange={(event) => personForm.setData('national_id', event.currentTarget.value)} />
          <TextInput id="edit-phone" labelText="Phone" value={personForm.data.phone}
            onChange={(event) => personForm.setData('phone', event.currentTarget.value)} />
          <TextInput id="edit-email" type="email" labelText="Contact email" value={personForm.data.email}
            onChange={(event) => personForm.setData('email', event.currentTarget.value)} />
          <TextInput id="edit-address" labelText="Physical address" value={personForm.data.physical_address}
            onChange={(event) => personForm.setData('physical_address', event.currentTarget.value)} />
          <div className="facility-staff__photo-field">
            <span className="cds--label">Staff photo</span>
            <input
              ref={photoInputRef}
              id="edit-staff-photo-upload"
              type="file"
              accept=".jpeg,.jpg,.png,.webp"
              hidden
              aria-label="Replace staff photo"
              onChange={(event) => personForm.setData('photo', event.currentTarget.files?.[0] ?? null)}
            />
            {photoPreviewUrl ? (
              <img className="facility-staff__photo-preview" src={photoPreviewUrl} alt="New staff photo preview" />
            ) : staff.photoUrl ? (
              <img className="facility-staff__photo-preview" src={staff.photoUrl} alt={`${staff.name} staff photo`} />
            ) : (
              <span className="facility-staff__photo-empty">No photo on record</span>
            )}
            <div className="facility-staff__photo-actions">
              <Button
                kind="secondary"
                size="sm"
                type="button"
                renderIcon={Edit}
                onClick={() => photoInputRef.current?.click()}
              >
                Replace photo
              </Button>
              {personForm.data.photo ? <span>{personForm.data.photo.name}</span> : null}
            </div>
            {personForm.errors.photo ? <p className="cds--form-requirement">{personForm.errors.photo}</p> : null}
          </div>
        </DockedPanel>
      ) : null}

      {workspace === 'onboard' && canOnboard ? (
        <DockedPanel
          key={`onboard:${staff.id}`}
          heading="Complete onboarding"
          primaryLabel="Complete onboarding"
          secondaryLabel="Cancel"
          onClose={closeWorkspace}
          onSubmit={(event) => submitAction(event, routes.workspaces.hrStaffOnboard(staff.id), 'joined_date')}
        >
          <p>
            Onboarding records the joined date, activates the staff record, and starts a due
            draft employment when you have permission to manage employment. Future-dated
            employment remains a draft until it is started separately.
          </p>
          <TextInput id="actual-joined-date" name="joined_date" type="date" labelText="Actual joined date"
            defaultValue={staff.joinedDate ?? today}
            onChange={() => setWorkspaceDirty(true)}
            required />
        </DockedPanel>
      ) : null}

      {workspace === 'employment-start' && canStartEmployment && staff.employment ? (
        <DockedPanel
          key={`employment-start:${staff.id}`}
          heading="Start employment"
          primaryLabel="Start employment"
          secondaryLabel="Cancel"
          onClose={closeWorkspace}
          onSubmit={(event) => submitAction(
            event,
            routes.workspaces.hrStaffEmploymentStart(staff.id, staff.employment!.id),
            'start_date',
          )}
        >
          <TextInput id="confirmed-employment-start-date" name="start_date" type="date"
            labelText="Confirmed employment start date"
            defaultValue={staff.employment.startDate ?? today}
            onChange={() => setWorkspaceDirty(true)}
            required />
        </DockedPanel>
      ) : null}

      {workspace === 'suspend' && canSuspend ? (
        <DockedPanel
          key={`suspend:${staff.id}`}
          heading="Suspend staff"
          primaryLabel="Confirm suspension"
          secondaryLabel="Cancel"
          onClose={closeWorkspace}
          onSubmit={(event) => submitAction(event, routes.workspaces.hrStaffSuspend(staff.id), 'reason')}
        >
          <TextInput
            id="suspension-reason"
            name="reason"
            labelText="Reason for suspension"
            onChange={() => setWorkspaceDirty(true)}
            required
          />
        </DockedPanel>
      ) : null}

      {workspace === 'terminate' && canTerminate ? (
        <DockedPanel
          key={`terminate:${staff.id}`}
          heading="Terminate staff"
          primaryLabel="Confirm termination"
          primaryDisabled={terminationBlockerMessages.length > 0}
          secondaryLabel="Cancel"
          danger
          onClose={closeWorkspace}
          onSubmit={terminateStaff}
        >
          {terminationBlockerMessages.length > 0 ? (
            <div role="status">
              <strong>Resolve these items before termination:</strong>
              <ul>
                {terminationBlockerMessages.map((message) => <li key={message}>{message}</li>)}
              </ul>
            </div>
          ) : (
            <p>Termination is permanent. Confirm the effective date and reason.</p>
          )}
          <TextInput id="termination-effective-date" name="terminated_date" type="date"
            labelText="Termination effective date" max={today}
            defaultValue={today}
            onChange={() => setWorkspaceDirty(true)}
            required />
          <TextInput id="termination-reason" name="reason" labelText="Reason for termination"
            onChange={() => setWorkspaceDirty(true)}
            required />
        </DockedPanel>
      ) : null}

      {workspace === 'invite' && canInviteUser && staff.linkedUserId === null ? (
        <DockedPanel
          key={`invite:${staff.id}`}
          heading="Invite account"
          primaryLabel={invitationForm.processing ? 'Sending invitation…' : 'Invite account and request roles'}
          primaryDisabled={invitationForm.processing || invitationForm.data.primary_role_key === ''}
          secondaryLabel="Cancel"
          onClose={closeWorkspace}
          onSubmit={inviteAccount}
        >
          <StaffIdentityBlock staff={staff} />
          <p className="staff-invite__note">The new account links to this staff record automatically.</p>

          <section className="staff-invite__section" aria-labelledby="staff-invite-account-heading">
            <h3 id="staff-invite-account-heading">Account</h3>
            <TextInput
              id="staff-invite-email"
              type="email"
              labelText="Account email"
              value={invitationForm.data.email}
              onChange={(event) => invitationForm.setData('email', event.currentTarget.value)}
              invalid={Boolean(invitationForm.errors.email)}
              invalidText={invitationForm.errors.email}
              required
            />
          </section>

          <section className="staff-invite__section" aria-labelledby="staff-invite-roles-heading">
            <h3 id="staff-invite-roles-heading">AfyaNova access roles</h3>
            <p className="staff-invite__note">
              These roles control application permissions and are separate from professional cadre and job position. Access activates immediately if you hold Identity grant authority. Otherwise, you can approve the access request you created after account setup.
            </p>
            <Select
              id="staff-invite-primary-role"
              labelText="Primary AfyaNova access role"
              value={invitationForm.data.primary_role_key}
              onChange={(event) => {
                const roleKey = event.currentTarget.value;
                invitationForm.setData('primary_role_key', roleKey);
                invitationForm.setData(
                  'secondary_role_keys',
                  invitationForm.data.secondary_role_keys.filter((key) => key !== roleKey),
                );
              }}
              invalid={Boolean(invitationForm.errors.primary_role_key)}
              invalidText={invitationForm.errors.primary_role_key}
              required
            >
              <SelectItem value="" text="Select primary role" />
              {invitationRoles.map((role) => (
                <SelectItem key={role.key} value={role.key} text={role.label} />
              ))}
            </Select>
            <CheckboxGroup
              legendText="Secondary AfyaNova access roles"
              helperText={invitationForm.data.secondary_role_keys.length >= 2
                ? 'Limit reached — deselect a role to choose a different one.'
                : `Select up to two additional roles · ${invitationForm.data.secondary_role_keys.length} of 2 selected`}
              invalid={Boolean(invitationForm.errors.secondary_role_keys)}
              invalidText={invitationForm.errors.secondary_role_keys}
            >
              {invitationRoles
                .filter((role) => role.key !== invitationForm.data.primary_role_key)
                .map((role) => {
                  const selected = invitationForm.data.secondary_role_keys.includes(role.key);
                  const limitReached = invitationForm.data.secondary_role_keys.length >= 2 && !selected;
                  return (
                    <Checkbox
                      key={role.key}
                      id={`staff-invite-secondary-${role.key}`}
                      labelText={role.label}
                      checked={selected}
                      disabled={limitReached}
                      onChange={(_, { checked }) => invitationForm.setData(
                        'secondary_role_keys',
                        checked
                          ? [...invitationForm.data.secondary_role_keys, role.key]
                          : invitationForm.data.secondary_role_keys.filter((key) => key !== role.key),
                      )}
                    />
                  );
                })}
            </CheckboxGroup>
          </section>

          <section className="staff-invite__section" aria-labelledby="staff-invite-justification-heading">
            <h3 id="staff-invite-justification-heading">Justification</h3>
            <TextInput
              id="staff-invite-reference"
              labelText="Change or ticket reference"
              value={invitationForm.data.change_reference}
              onChange={(event) => invitationForm.setData('change_reference', event.currentTarget.value)}
              invalid={Boolean(invitationForm.errors.change_reference)}
              invalidText={invitationForm.errors.change_reference}
              required
            />
            <TextArea
              id="staff-invite-reason"
              labelText="Reason for access"
              value={invitationForm.data.access_reason}
              onChange={(event) => invitationForm.setData('access_reason', event.currentTarget.value)}
              invalid={Boolean(invitationForm.errors.access_reason)}
              invalidText={invitationForm.errors.access_reason}
              rows={3}
              maxLength={500}
              required
            />
          </section>
        </DockedPanel>
      ) : null}

      {workspace === 'resend-invitation' && canResendInvitation && staff.setupInvitation?.canResend ? (
        <DockedPanel
          key={`resend-invitation:${staff.id}`}
          heading="Resend setup invitation"
          primaryLabel={resendInvitationForm.processing ? 'Sending…' : 'Resend invitation'}
          primaryDisabled={resendInvitationForm.processing}
          secondaryLabel="Cancel"
          onClose={closeWorkspace}
          onSubmit={resendInvitation}
        >
          <p className="staff-invite__note">
            A new verification link will be sent to {staff.linkedUserEmail ?? 'the linked account'}. The link expires in 60 minutes.
          </p>
          <TextInput
            id="staff-resend-reference"
            labelText="Change or ticket reference"
            value={resendInvitationForm.data.change_reference}
            onChange={(event) => resendInvitationForm.setData('change_reference', event.currentTarget.value)}
            invalid={Boolean(resendInvitationForm.errors.change_reference)}
            invalidText={resendInvitationForm.errors.change_reference}
            required
          />
          <TextArea
            id="staff-resend-reason"
            labelText="Reason for resend"
            value={resendInvitationForm.data.access_reason}
            onChange={(event) => resendInvitationForm.setData('access_reason', event.currentTarget.value)}
            invalid={Boolean(resendInvitationForm.errors.access_reason)}
            invalidText={resendInvitationForm.errors.access_reason}
            rows={3}
            maxLength={500}
            required
          />
        </DockedPanel>
      ) : null}

      {workspace === 'link' && canLinkStaff && staff.linkedUserId === null ? (
        <DockedPanel
          key={`link:${staff.id}`}
          heading="Link existing account"
          primaryLabel="Link account"
          secondaryLabel="Cancel"
          onClose={closeWorkspace}
          onSubmit={linkAccount}
        >
          <StaffIdentityBlock staff={staff} />
          <p className="staff-invite__note">Linking alone does not grant facility access.</p>
          <TextInput
            id="existing-account-email"
            name="email"
            type="email"
            labelText="Existing verified AfyaNova account email"
            placeholder="Existing account email"
            onChange={() => setWorkspaceDirty(true)}
            required
          />
        </DockedPanel>
      ) : null}

      {/* Mounted only when open: Carbon Modal stays in the DOM hidden
          when closed, which would leak the heading into queries. */}
      <DiscardChangesModal
        pending={pendingSwitch !== null}
        onKeep={cancelPendingSwitch}
        onDiscard={confirmPendingSwitch}
      />
    </div>
  );
}
