import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { DashboardWidgets } from './DashboardWidgets';
import type { DashboardWidget } from '@/features/dashboard/types/dashboard';

vi.mock('@inertiajs/react', () => ({
  Link: ({ children, href, ...props }: { children: ReactNode; href: string }) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

const widgets: DashboardWidget[] = [
  { id: 'scheduled-today', title: 'Scheduled today', count: 4, href: '/workspaces/appointments/calendar' },
  { id: 'arrived-today', title: 'Arrived today', count: 2, href: '/workspaces/reception' },
  { id: 'active-visits', title: 'Active visits', count: 1, href: '/workspaces/reception' },
];

describe('DashboardWidgets', () => {
  it('renders one linked card per widget with its count', () => {
    render(<DashboardWidgets widgets={widgets} />);

    expect(screen.getByText('4')).toBeInTheDocument();
    expect(screen.getByText('Scheduled today')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open Scheduled today' })).toHaveAttribute(
      'href',
      '/workspaces/appointments/calendar',
    );
    expect(screen.getByRole('link', { name: 'Open Arrived today' })).toHaveAttribute(
      'href',
      '/workspaces/reception',
    );
    expect(screen.getAllByRole('link')).toHaveLength(3);
  });

  it('renders nothing when every widget is restricted', () => {
    const { container } = render(<DashboardWidgets widgets={[]} />);

    expect(container).toBeEmptyDOMElement();
  });
});
