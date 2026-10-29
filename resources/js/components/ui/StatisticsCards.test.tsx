import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StatisticsCards } from './StatisticsCards';

vi.mock('@inertiajs/react', () => ({
  Link: ({ children, href, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

describe('StatisticsCards', () => {
  it('renders consistent static and linked statistic cards', () => {
    render(
      <StatisticsCards
        label="Example statistics"
        cards={[
          { id: 'waiting', label: 'Waiting', value: 4, detail: 'Average wait 12 min' },
          {
            id: 'arrived',
            label: 'Arrived',
            value: 2,
            href: '/reception',
            ariaLabel: 'Arrived: 2',
            selected: true,
            actionLabel: 'View workspace',
          },
        ]}
      />,
    );

    const region = screen.getByRole('region', { name: 'Example statistics' });
    expect(region).toHaveClass('afy-stats', 'afy-stats--connected');
    expect(region.querySelectorAll('.afy-stat')).toHaveLength(2);
    expect(screen.getByText('Average wait 12 min')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Arrived: 2' })).toHaveAttribute('href', '/reception');
    expect(screen.getByRole('link', { name: 'Arrived: 2' })).toHaveAttribute('aria-current', 'true');
    expect(screen.getByText('View workspace')).toBeInTheDocument();
  });

  it('can opt out of the connected band', () => {
    render(
      <StatisticsCards
        variant="separate"
        label="Detached statistics"
        cards={[{ id: 'waiting', label: 'Waiting', value: 4 }]}
      />,
    );

    expect(screen.getByRole('region', { name: 'Detached statistics' }))
      .not.toHaveClass('afy-stats--connected');
  });
});
