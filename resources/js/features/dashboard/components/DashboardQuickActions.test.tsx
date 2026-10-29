import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DashboardQuickActions } from './DashboardQuickActions';
import type { QuickAction } from '@/features/dashboard/types/dashboard';

const state = vi.hoisted(() => ({
  visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { visit: state.visit },
  Link: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

const actions: QuickAction[] = [
  { id: 'register-patient', title: 'Register new patient', href: '/workspaces/patient/register' },
  { id: 'check-in-walk-in', title: 'Check in walk-in patient', href: '/workspaces/reception' },
  { id: 'book-appointment', title: 'Book appointment', href: '/workspaces/appointments/calendar' },
];

describe('DashboardQuickActions', () => {
  it('renders the first action as the primary and the rest in the combo menu', () => {
    render(<DashboardQuickActions actions={actions} />);

    expect(screen.getByRole('button', { name: 'Register new patient' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Check in walk-in patient' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Additional actions' }));

    expect(screen.getByRole('menuitem', { name: 'Check in walk-in patient' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Book appointment' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('menuitem', { name: 'Book appointment' }));
    expect(state.visit).toHaveBeenCalledWith('/workspaces/appointments/calendar');
  });

  it('routes the primary action through the combo button', () => {
    render(<DashboardQuickActions actions={actions} />);

    fireEvent.click(screen.getByRole('button', { name: 'Register new patient' }));
    expect(state.visit).toHaveBeenCalledWith('/workspaces/patient/register');
  });

  it('renders a single action as a plain button with no menu trigger', () => {
    render(<DashboardQuickActions actions={actions.slice(0, 1)} />);

    expect(screen.getByRole('button', { name: 'Register new patient' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Additional actions' })).not.toBeInTheDocument();
  });

  it('renders nothing without permitted actions', () => {
    const { container } = render(<DashboardQuickActions actions={[]} />);

    expect(container).toBeEmptyDOMElement();
  });
});
