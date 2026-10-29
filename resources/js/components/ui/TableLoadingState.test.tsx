import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TableLoadingState } from './TableLoadingState';

describe('TableLoadingState', () => {
  it('renders a labelled busy skeleton region for table first load', () => {
    render(<TableLoadingState label="Loading patient results" />);

    const region = screen.getByRole('status', { name: 'Loading patient results' });
    expect(region).toBeInTheDocument();
    expect(region).toHaveAttribute('aria-busy', 'true');
    // The skeleton is itself a table-shaped placeholder, not data rows.
    expect(region.querySelector('table.cds--skeleton')).not.toBeNull();
  });
});
