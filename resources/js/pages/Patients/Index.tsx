import { router } from '@inertiajs/react';
import { Button } from '@carbon/react';
import type { ReactNode } from 'react';
import { ErrorState, PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout';
import { permissions, useCan } from '@/lib/authorization';
import { routes } from '@/app/routes';
import { PatientSearchBrowse } from '@/features/patients/components';
import type { PatientSearchBrowseProps } from '@/features/patients/types/PatientSearchBrowseProps';
import { Add } from '@/icons';

export default function PatientsIndex(props: PatientSearchBrowseProps): ReactNode {
  const can = useCan();
  const canRegister = can(permissions.patient.create);

  return (
    <AppLayout>
      <PageHeader
        title="Patients"
        description="Search patient records or browse newest registrations."
        actions={canRegister ? (
          <Button size="lg" renderIcon={Add}
            type="button"
            onClick={() => router.visit(`${routes.workspaces.patientRegister}?after_registration=PATIENT_CHART`)}
          >
            Register patient
          </Button>
        ) : null}
      />
      {can(permissions.patient.read) ? (
        <PatientSearchBrowse {...props} />
      ) : (
        <ErrorState title="Patient access is not available" message="You do not have permission to view patient records." />
      )}
    </AppLayout>
  );
}