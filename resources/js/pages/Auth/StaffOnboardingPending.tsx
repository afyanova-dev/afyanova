import { Link, router } from '@inertiajs/react';
import { Button } from '@carbon/react';
import type { ReactNode } from 'react';
import { routes } from '@/app/routes';
import { staffStatusLabel } from '@/lib/staffStatus';
import { AuthLayout } from '@/layouts/AuthLayout';
import { Logout } from '@/icons';

interface StaffOnboardingPendingProps {
  email: string;
  staffStatus: string;
}

export default function StaffOnboardingPending({
  email,
  staffStatus,
}: StaffOnboardingPendingProps): ReactNode {
  return (
    <AuthLayout ariaLabel="Onboarding pending">
      <h1>Your account is ready</h1>
      <p>
        You are signed in as <strong>{email}</strong>.
      </p>
      <p>
        Your staff record is <strong>{staffStatusLabel(staffStatus)}</strong>.
        An HR administrator must complete your staff onboarding before you can open
        clinical or administrative workspaces. You can still manage your signed-in
        devices or sign out below.
      </p>
      <Button kind="primary" size="lg" renderIcon={Logout} onClick={() => router.post(routes.logout)}>
        Sign out
      </Button>
      <p className="afy-auth__nav">
        <Link href={routes.sessions.index} className="cds--link">
          Manage your signed-in devices
        </Link>
      </p>
    </AuthLayout>
  );
}
