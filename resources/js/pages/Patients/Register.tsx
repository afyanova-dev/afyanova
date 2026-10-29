import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ErrorState, PageHeader } from '@/components/ui';
import { RegisterPatientForm } from '@/features/patients/forms/RegisterPatientForm';
import {
  isDuplicateReview,
  type PatientRegistrationPageProps,
} from '@/features/patients/types/registration';
import { AppLayout } from '@/layouts/AppLayout';
import { permissions, useCan } from '@/lib/authorization';
import type { SharedProps } from '@/types/auth';

export default function PatientRegister({
  duplicateReview,
  formValues,
  hasSavedDraft,
  defaultAfterRegistration,
}: PatientRegistrationPageProps): ReactNode {
  const { auth, flash } = usePage<SharedProps>().props;
  const can = useCan();
  const review = duplicateReview ?? (isDuplicateReview(flash.duplicate_review) ? flash.duplicate_review : null);
  const destinationDefault = defaultAfterRegistration
    ?? (can('encounter.encounters.create') ? 'FRONT_DESK' : 'PATIENT_LIST');

  return (
    <AppLayout>
      {!can(permissions.patient.create) ? (
        <>
          <PageHeader title="Register patient" description="All fields are required unless marked optional." />
          <ErrorState title="Patient registration is not available" message="You do not have permission to register patients." />
        </>
      ) : !auth.activeFacility ? (
        <>
          <PageHeader title="Register patient" description="All fields are required unless marked optional." />
          <ErrorState title="An active facility is required" message="Choose an active facility in the application header before registering a patient." />
        </>
      ) : (
        <RegisterPatientForm
          duplicateReview={review}
          initialValues={formValues}
          hasSavedDraft={hasSavedDraft}
          defaultAfterRegistration={destinationDefault}
          canOverrideDuplicate={can(permissions.patient.overrideDuplicate)}
          canManageIdentifiers={can(permissions.patient.identifiersManage)}
          canRecordAddress={can(permissions.patient.update)}
        />
      )}
    </AppLayout>
  );
}
