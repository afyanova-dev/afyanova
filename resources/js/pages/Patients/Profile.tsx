import type { ReactNode } from 'react';
import { ErrorState } from '@/components/ui';
import { PatientChart } from '@/features/patients/components/PatientChart';
import type { PatientChartProps } from '@/features/patients/types/chart';
import { AppLayout } from '@/layouts/AppLayout';
import { permissions, useCan } from '@/lib/authorization';

export default function PatientProfile(props: PatientChartProps): ReactNode {
  const can = useCan();

  return (
    <AppLayout>
      {!can(permissions.patient.read) ? (
        <ErrorState
          title="Patient chart is not available"
          message="You do not have permission to view patient records."
        />
      ) : (
        <PatientChart {...props} />
      )}
    </AppLayout>
  );
}
