import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CarbonDataTable } from './CarbonDataTable';

describe('CarbonDataTable', () => {
  it('uses the shared Carbon table layout with toolbar and custom cells', () => {
    render(
      <CarbonDataTable
        rows={[{ id: 'facility-1', name: 'Central Clinic', status: 'ACTIVE' }]}
        headers={[
          { key: 'name', header: 'Facility' },
          { key: 'status', header: 'Status' },
        ]}
        title="1 facility"
        ariaLabel="Facilities"
        toolbarLabel="Facility table toolbar"
        toolbar={<button type="button">Add facility</button>}
        emptyState={<p>No facilities</p>}
        renderCell={(cell) => cell.info.header === 'status' ? <strong>{cell.value}</strong> : cell.value}
      />,
    );

    expect(screen.getByRole('table', { name: '1 facility' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add facility' }).closest('[aria-label="Facility table toolbar"]')).not.toBeNull();
    expect(screen.getByText('Central Clinic')).toBeInTheDocument();
    expect(screen.getByText('ACTIVE').tagName).toBe('STRONG');
  });

  it('shows the supplied empty state when there are no rows', () => {
    render(
      <CarbonDataTable
        rows={[]}
        headers={[{ key: 'name', header: 'Name' }]}
        title="No records"
        ariaLabel="Records"
        emptyState={<p>No records available</p>}
      />,
    );

    expect(screen.getByText('No records available')).toBeInTheDocument();
    expect(screen.queryByRole('table', { name: 'Records' })).not.toBeInTheDocument();
  });
});
