import { Link, usePage } from '@inertiajs/react';
import { Button } from '@carbon/react';
import type { ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { routes } from '@/app/routes';
import type { SharedProps } from '@/types';
import { Dashboard } from '@/icons';

export default function ServerError(): ReactNode {
  const { props } = usePage<SharedProps>();

  return (
    <AuthLayout>
      <p style={{ margin: 0 }}>500</p>
      <h1 style={{ marginBlockStart: '0.25rem' }}>Something went wrong</h1>
      <p>Try again. If the problem continues, share this reference with support:</p>
      {props.correlationId ? <p aria-label="Correlation ID">{props.correlationId}</p> : null}
      <Button size="lg" renderIcon={Dashboard} as={Link} href={routes.dashboard}>
        Go to dashboard
      </Button>
    </AuthLayout>
  );
}