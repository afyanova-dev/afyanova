import { Link } from '@inertiajs/react';
import { Button } from '@carbon/react';
import type { ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { routes } from '@/app/routes';
import { Dashboard } from '@/icons';

export default function NotFound(): ReactNode {
  return (
    <AuthLayout>
      <p style={{ margin: 0 }}>404</p>
      <h1 style={{ marginBlockStart: '0.25rem' }}>Not found</h1>
      <p>The page you requested does not exist.</p>
      <Button size="lg" renderIcon={Dashboard} as={Link} href={routes.dashboard}>
        Go to dashboard
      </Button>
    </AuthLayout>
  );
}