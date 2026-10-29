import { PageHeader } from '@/components/ui';
import { HRWorkflowFeedback } from '@/features/hr/components/HRWorkflowFeedback';
import { StaffCreateForm } from '@/features/hr/components/StaffCreateForm';
import type { DepartmentOption, FacilitySummary, PositionOption, UnitOption } from '@/features/hr/types';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface StaffCreatePageProps {
  facility: FacilitySummary;
  departments: DepartmentOption[];
  units: UnitOption[];
  positions: PositionOption[];
  canManageEmployment: boolean;
  canManagePositions: boolean;
  canInviteUser: boolean;
  invitationRoles: { key: string; label: string }[];
  errors?: Record<string, string>;
}

export default function StaffCreatePage(props: StaffCreatePageProps): React.JSX.Element {
  return (
    <AppLayout>
      <main className="afyanova-page facility-staff">
        <PageHeader
          title="Add staff"
          description={`Create a staff record and request assignment to ${props.facility.name}.`}
        />
        <HRWorkflowFeedback errors={props.errors} />
        <StaffCreateForm {...props} />
      </main>
    </AppLayout>
  );
}
