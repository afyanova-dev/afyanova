import type { ReactNode } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';

interface HealthProps {
  checks: {
    postgres: string;
    redis: string;
    queue: string;
  };
  correlationId: string;
}

const checkLabels: Record<keyof HealthProps['checks'], string> = {
  postgres: 'PostgreSQL',
  redis: 'Redis',
  queue: 'Queue',
};
const checkKeys: Array<keyof HealthProps['checks']> = ['postgres', 'redis', 'queue'];

export default function Health({ checks, correlationId }: HealthProps): ReactNode {
  return (
    <AuthLayout>
      <h1>System health</h1>
      <p>Dependency availability for this application instance.</p>
      <ul aria-label="System dependency checks">
        {checkKeys.map((key) => (
          <li key={key}>
            <strong>{checkLabels[key]}:</strong> {checks[key]}
          </li>
        ))}
      </ul>
      <p>Reference: {correlationId}</p>
    </AuthLayout>
  );
}
