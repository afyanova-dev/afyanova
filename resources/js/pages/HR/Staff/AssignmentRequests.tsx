import { Button } from '@carbon/react';
import { Link } from '@inertiajs/react';
import { routes } from '@/app/routes';
import { PageHeader } from '@/components/ui';
import { AssignmentRequestsPanel } from '@/features/hr/components/AssignmentRequestsPanel';
import { HRWorkflowFeedback } from '@/features/hr/components/HRWorkflowFeedback';
import type {
  DepartmentOption,
  FacilitySummary,
  PendingAssignmentRequest,
  RejectedAssignmentRequest,
  UnitOption,
} from '@/features/hr/types';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface AssignmentRequestsPageProps {
  facility: FacilitySummary;
  pendingRequests: PendingAssignmentRequest[];
  rejectedRequests: RejectedAssignmentRequest[];
  departments: DepartmentOption[];
  units: UnitOption[];
  canReviewAssignments: boolean;
  canManageStaff: boolean;
  canManagePositions: boolean;
  errors?: Record<string, string>;
}

export default function AssignmentRequestsPage(props: AssignmentRequestsPageProps): React.JSX.Element {
  return (
    <AppLayout>
      <main className="afyanova-page facility-staff">
        <PageHeader
          title="Placement approvals"
          description={`Review staff placement requests for ${props.facility.name}.`}
          actions={(
            <Button as={Link} kind="ghost" size="sm" href={routes.workspaces.hrStaffOnboarding}>
              Back to onboarding
            </Button>
          )}
        />
        <HRWorkflowFeedback errors={props.errors} />
        <AssignmentRequestsPanel {...props} />
      </main>
    </AppLayout>
  );
}
