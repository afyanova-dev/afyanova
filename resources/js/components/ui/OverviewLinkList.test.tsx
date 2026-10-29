import { fireEvent, render, screen } from '@testing-library/react';
import type { ComponentType, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { OverviewLinkList } from './OverviewLinkList';

const state = vi.hoisted(() => ({
  visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: { visit: state.visit },
  Link: ({ children, href }: { children: ReactNode; href: string }) => (
    <a href={href} data-inertia-link>{children}</a>
  ),
}));

const TestIcon: ComponentType = () => <span>icon</span>;

const items = [
  { id: 'a', href: '/a', title: 'Workspace A', renderIcon: TestIcon },
  { id: 'b', href: '/b', title: 'Workspace B' },
];

describe('OverviewLinkList', () => {
  beforeEach(() => {
    state.visit.mockReset();
  });

  it('renders Carbon ClickableTile launcher tiles by default', () => {
    render(<OverviewLinkList title="Workspaces" items={items} emptyMessage="None" />);

    expect(screen.getByRole('link', { name: /Workspace A/ })).toHaveAttribute('href', '/a');
    expect(screen.getByRole('link', { name: /Workspace B/ })).toHaveAttribute('href', '/b');
  });

  it('navigates tiles through Inertia instead of a full page load', () => {
    render(<OverviewLinkList title="Workspaces" items={items} emptyMessage="None" />);

    const clickEvent = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    const notCancelled = screen.getByRole('link', { name: /Workspace A/ }).dispatchEvent(clickEvent);

    expect(state.visit).toHaveBeenCalledWith('/a');
    // preventDefault() makes dispatchEvent return false.
    expect(notCancelled).toBe(false);
  });

  it('leaves modified clicks to the browser', () => {
    render(
      // Prevent jsdom's unimplemented navigation; our handler still sees an
      // unprevented event because this runs after Inertia's click handler.
      <div onClick={(event) => event.preventDefault()}>
        <OverviewLinkList title="Workspaces" items={items} emptyMessage="None" />
      </div>,
    );

    fireEvent.click(screen.getByRole('link', { name: /Workspace A/ }), { ctrlKey: true });

    expect(state.visit).not.toHaveBeenCalled();
  });

  it('renders the legacy rows layout with Inertia links', () => {
    render(<OverviewLinkList title="Workspaces" items={items} emptyMessage="None" layout="rows" />);

    const link = screen.getByRole('link', { name: 'Workspace A' });

    expect(link).toHaveAttribute('data-inertia-link');
    expect(link).toHaveAttribute('href', '/a');
  });

  it('renders disabled status items as non-navigable tiles', () => {
    render(
      <OverviewLinkList
        title="Workspaces"
        items={[
          {
            id: 'pending',
            title: 'HR / Staff Manager',
            description: 'Central Clinic',
            status: 'Under review',
            disabled: true,
          },
        ]}
        emptyMessage="None"
      />,
    );

    const tile = screen.getByText('HR / Staff Manager').closest('a') as HTMLAnchorElement;

    expect(tile).toHaveAttribute('aria-disabled', 'true');
    expect(tile).not.toHaveAttribute('href');
    expect(tile).toHaveClass('afy-link-list__tile--pending');
    expect(screen.getByText('Central Clinic')).toBeInTheDocument();
    expect(screen.getByText('Under review')).toBeInTheDocument();

    fireEvent.click(tile);
    expect(state.visit).not.toHaveBeenCalled();
  });

  it('renders the empty message with no items', () => {
    render(<OverviewLinkList title="Workspaces" items={[]} emptyMessage="None" />);

    expect(screen.getByText('None')).toBeInTheDocument();
  });
});
