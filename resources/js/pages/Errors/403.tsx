import { Button } from '@carbon/react';
import type { ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { routes } from '@/app/routes';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';
import { Dashboard } from '@/icons';

export default function Forbidden(): ReactNode {
  const { props } = usePage<SharedProps>();

  return (
    <AuthLayout>
      <p style={{ margin: 0 }}>403</p>
      <h1 style={{ marginBlockStart: '0.25rem' }}>Access denied</h1>
      <p>You do not have permission to open this page.</p>
      <Button size="lg" renderIcon={Dashboard} href={props.auth.user ? routes.dashboard : routes.login}>
        {props.auth.user ? 'Go to dashboard' : 'Go to log in'}
      </Button>
    </AuthLayout>
  );
}