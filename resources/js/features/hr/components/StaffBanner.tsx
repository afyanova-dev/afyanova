import { Button, Tag } from '@carbon/react';
import { useEffect, useState, type ReactNode } from 'react';
import { ChevronDown, ChevronUp, GenderFemale, GenderMale } from '@/icons';
import { ageDisplay, formatClinicalDateTime, formatDobDate } from '@/lib/formatting';
import { staffStatusLabel, staffStatusTagType } from '@/lib/staffStatus';
import type { StaffProfileData } from './StaffProfile';

function readableLabel(value: string): string {
  return value
    .toLowerCase()
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((part) => part[0]!.toUpperCase() + part.slice(1))
    .join(' ');
}

function StaffPhoto({ staffName, photoUrl }: { staffName: string; photoUrl: string | null }): ReactNode {
  const [unavailable, setUnavailable] = useState(photoUrl === null);

  useEffect(() => {
    setUnavailable(photoUrl === null);
  }, [photoUrl]);

  if (photoUrl && !unavailable) {
    return (
      <img
        className="patient-chart__photo"
        src={photoUrl}
        alt={`${staffName} staff photo`}
        width={96}
        height={96}
        onError={() => setUnavailable(true)}
      />
    );
  }

  return (
    <div
      role="img"
      aria-label="Staff photo placeholder"
      className="patient-chart__photo patient-chart__photo--empty"
    >
      {staffName.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase()}
    </div>
  );
}

export function staffAssignmentLabel(staff: StaffProfileData): string {
  if (staff.pendingAssignmentRequestId && !staff.hasCurrentFacilityAssignment) {
    return 'Placement awaiting confirmation';
  }
  if (staff.unitName) return staff.unitName;
  if (staff.departmentName) return staff.departmentName;

  switch (staff.assignmentScope) {
    case 'UNIT': return 'Unit assignment';
    case 'DEPARTMENT': return 'Department assignment';
    case 'FACILITY': return 'Facility-wide';
    default: return 'Assignment not specified';
  }
}

/**
 * Chart-style banner for the staff record: photo, identity, state tag,
 * actions menu, and a collapsible detail grid. Mirrors the patient chart
 * banner (OpenMRS pattern) — same banner header, grouped details, and
 * Show more/less disclosure so staff records read like patient records.
 */
export function StaffBanner({
  staff,
  photoUrl,
  menu,
}: {
  staff: StaffProfileData;
  photoUrl: string | null;
  menu: ReactNode;
}): ReactNode {
  const [detailsExpanded, setDetailsExpanded] = useState(false);
  const profile = staff.personProfile;
  const assignment = staffAssignmentLabel(staff);
  const birthDate = profile ? formatDobDate(profile.dateOfBirth) ?? profile.dateOfBirth : null;
  const age = profile ? ageDisplay(profile.dateOfBirth) : null;
  const normalizedGender = profile?.gender.toLowerCase() ?? '';
  const genderIcon = normalizedGender.includes('female')
    ? <GenderFemale size={16} aria-hidden="true" />
    : normalizedGender.includes('male')
      ? <GenderMale size={16} aria-hidden="true" />
      : null;

  return (
    <header aria-label="Staff header" className="patient-chart__banner">
      <div className="patient-chart__banner-top">
        <div className="patient-chart__identity">
          <div className="patient-chart__identity-main">
            <StaffPhoto staffName={staff.name} photoUrl={photoUrl} />
            <div className="patient-chart__identity-copy">
              <p className="patient-chart__eyebrow">Staff</p>
              <div className="patient-chart__name-row">
                <h1>{staff.name}</h1>
                {profile?.gender ? (
                  <span className="patient-chart__sex">
                    {genderIcon}
                    {readableLabel(profile.gender)}
                  </span>
                ) : null}
                <Tag type={staffStatusTagType(staff.status)}>{staffStatusLabel(staff.status)}</Tag>
              </div>
              <div className="patient-chart__identity-meta" aria-label="Staff details">
                {staff.positionTitle ? (
                  <span className="patient-chart__meta-item">{staff.positionTitle}</span>
                ) : null}
                {staff.positionTitle ? (
                  <span className="patient-chart__meta-divider" aria-hidden="true">·</span>
                ) : null}
                <span className="patient-chart__meta-item">{assignment}</span>
                <span className="patient-chart__meta-divider" aria-hidden="true">·</span>
                <span className="patient-chart__meta-item patient-chart__meta-item--number">
                  Staff number: {staff.staffNumber}
                </span>
              </div>
            </div>
          </div>
        </div>
        <div className="patient-chart__banner-actions">
          <div className="patient-chart__banner-menu">{menu}</div>
          <Button
            className="patient-chart__details-toggle"
            kind="ghost"
            size="lg"
            type="button"
            renderIcon={detailsExpanded ? ChevronUp : ChevronDown}
            aria-expanded={detailsExpanded}
            aria-controls={detailsExpanded ? 'staff-extra-details' : undefined}
            onClick={() => setDetailsExpanded((expanded) => !expanded)}
          >
            {detailsExpanded ? 'Show less' : 'Show more'}
          </Button>
        </div>
      </div>
      {detailsExpanded ? (
        <div
          id="staff-extra-details"
          className="patient-chart__extra-details patient-chart__extra-details--staff"
        >
          <div className="patient-chart__details-grid">
            <section
              className="patient-chart__details-group"
              aria-labelledby="staff-details-demographics"
            >
              <h2 id="staff-details-demographics">Demographics</h2>
              <dl className="patient-chart__details-list">
                {birthDate ? (
                  <div>
                    <dt>Date of birth</dt>
                    <dd>{birthDate}</dd>
                  </div>
                ) : null}
                {age ? (
                  <div>
                    <dt>Age</dt>
                    <dd>{age}</dd>
                  </div>
                ) : null}
                {profile?.gender ? (
                  <div>
                    <dt>Gender</dt>
                    <dd>{readableLabel(profile.gender)}</dd>
                  </div>
                ) : null}
                {profile?.nationalId ? (
                  <div>
                    <dt>National ID</dt>
                    <dd>{profile.nationalId}</dd>
                  </div>
                ) : null}
              </dl>
            </section>

            {staff.phone || staff.email || profile?.physicalAddress ? (
              <section
                className="patient-chart__details-group"
                aria-labelledby="staff-details-contact"
              >
                <h2 id="staff-details-contact">Contact</h2>
                <dl className="patient-chart__details-list">
                  {staff.phone ? (
                    <div>
                      <dt>Phone</dt>
                      <dd>{staff.phone}</dd>
                    </div>
                  ) : null}
                  {staff.email ? (
                    <div>
                      <dt>Email</dt>
                      <dd>{staff.email}</dd>
                    </div>
                  ) : null}
                  {profile?.physicalAddress ? (
                    <div>
                      <dt>Physical address</dt>
                      <dd>{profile.physicalAddress}</dd>
                    </div>
                  ) : null}
                </dl>
              </section>
            ) : null}

            <section
              className="patient-chart__details-group"
              aria-labelledby="staff-details-record"
            >
              <h2 id="staff-details-record">Staff record</h2>
              <dl className="patient-chart__details-list">
                <div>
                  <dt>Status</dt>
                  <dd>{readableLabel(staff.status)}</dd>
                </div>
                <div>
                  <dt>Position</dt>
                  <dd>{staff.positionTitle ?? 'Not recorded'}</dd>
                </div>
                <div>
                  <dt>Facility assignment</dt>
                  <dd>{assignment}</dd>
                </div>
                <div>
                  <dt>Joined date</dt>
                  <dd>{staff.joinedDate ?? 'Not yet onboarded'}</dd>
                </div>
              </dl>
            </section>

            {staff.employment ? (
              <section
                className="patient-chart__details-group"
                aria-labelledby="staff-details-employment"
              >
                <h2 id="staff-details-employment">Employment</h2>
                <dl className="patient-chart__details-list">
                  <div>
                    <dt>Status</dt>
                    <dd>{readableLabel(staff.employment.status)}</dd>
                  </div>
                  <div>
                    <dt>Contract</dt>
                    <dd>{readableLabel(staff.employment.contractType)}</dd>
                  </div>
                  <div>
                    <dt>FTE</dt>
                    <dd>{staff.employment.fte}</dd>
                  </div>
                  {staff.employment.startDate ? (
                    <div>
                      <dt>Start date</dt>
                      <dd>{formatClinicalDateTime(staff.employment.startDate) ?? staff.employment.startDate}</dd>
                    </div>
                  ) : null}
                </dl>
              </section>
            ) : null}

            <section
              className="patient-chart__details-group"
              aria-labelledby="staff-details-account"
            >
              <h2 id="staff-details-account">Account</h2>
              <dl className="patient-chart__details-list">
                <div>
                  <dt>AfyaNova account</dt>
                  <dd>{staff.linkedUserId
                    ? staff.linkedUserEmail ?? 'Account details unavailable'
                    : 'No account linked'}</dd>
                </div>
              </dl>
            </section>

            {(staff.status === 'SUSPENDED' && staff.suspensionReason) || staff.status === 'TERMINATED' ? (
              <section
                className="patient-chart__details-group"
                aria-labelledby="staff-details-status"
              >
                <h2 id="staff-details-status">Status details</h2>
                <dl className="patient-chart__details-list">
                  {staff.status === 'SUSPENDED' && staff.suspensionReason ? (
                    <div>
                      <dt>Suspension reason</dt>
                      <dd>{staff.suspensionReason}</dd>
                    </div>
                  ) : null}
                  {staff.status === 'TERMINATED' ? (
                    <div>
                      <dt>Termination</dt>
                      <dd>{`Terminated ${staff.terminatedDate ?? 'date unavailable'}${staff.terminationReason ? ` · ${staff.terminationReason}` : ''}`}</dd>
                    </div>
                  ) : null}
                </dl>
              </section>
            ) : null}
          </div>
        </div>
      ) : null}
    </header>
  );
}
