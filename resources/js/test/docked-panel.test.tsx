import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FormEvent } from 'react';
import { DockedPanel } from '@/components/panels';

const state = vi.hoisted(() => ({
  granted: new Set<string>(['sch.access', 'sch.appointment.book']),
  getJsonImpl: null as null | ((url: string) => Promise<unknown>),
}));

vi.mock('@inertiajs/react', async () => {
  const React = await import('react');
  return {
    useForm: (initial: Record<string, unknown>) => {
      const [data, setState] = React.useState(initial);
      return {
        data,
        setData: (key: string, value: unknown) => {
          setState((current) => ({ ...current, [key]: value }));
        },
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
      };
    },
  };
});

vi.mock('@/lib/http', () => ({
  getJson: (url: string) => state.getJsonImpl?.(url) ?? Promise.reject(new Error('no mock')),
}));

vi.mock('@/lib/authorization', () => ({
  permissions: {
    scheduling: { access: 'sch.access', book: 'sch.appointment.book' },
  },
  useCan: () => (permission: string) => state.granted.has(permission),
}));

beforeEach(() => {
  vi.clearAllMocks();
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
  state.getJsonImpl = (url: string) => {
    if (url.includes('appointment-options')) {
      return Promise.resolve({
        types: [{ id: 'type-1', code: 'GEN', name: 'General', durationMinutes: 30 }],
        resources: [{ id: 'res-1', name: 'Dr. A', resourceType: 'DOCTOR' }],
      });
    }
    return Promise.resolve({ date: '2026-10-05', slots: [] });
  };
});

describe('docked panel', () => {
  it('supports read-only content with header close and no orphan footer button', () => {
    const onClose = vi.fn();
    render(
      <DockedPanel heading="Day appointments" onClose={onClose}>
        <p>Three appointments</p>
      </DockedPanel>,
    );

    expect(screen.getByRole('dialog', { name: 'Day appointments' })).toBeInTheDocument();
    expect(screen.getByText('Three appointments')).toBeInTheDocument();
    // Header X is the close path — no footer button orphan.
    expect(screen.getByRole('button', { name: 'Close Day appointments' })).toBeInTheDocument();
    expect(document.querySelector('.cds--modal-footer')).toBeNull();
    expect(document.querySelector('.cds--btn-set')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
  });

  it('renders a backdrop-free panel docked below the top bar with escape close', () => {
    const onClose = vi.fn();
    const onSubmit = vi.fn((event: FormEvent) => event.preventDefault());
    render(
      <DockedPanel heading="Test panel" primaryLabel="Save" onClose={onClose} onSubmit={onSubmit}>
        <p>Body</p>
        <button type="button" className="cds--btn">Body action</button>
      </DockedPanel>,
    );

    const dialog = screen.getByRole('dialog', { name: 'Test panel' });
    expect(dialog).toBeInTheDocument();
    // OpenMRS workspace: no dimming backdrop, stays below the top bar.
    expect(dialog).toHaveStyle({ top: '3rem' });
    expect(dialog).toHaveStyle({ width: 'min(34rem, 100%)' });
    expect(document.body.textContent).not.toMatch(/rgba\(0, 0, 0, 0\.5\)/);
    // Header X + footer Cancel are both close paths on form panels.
    expect(screen.getByRole('button', { name: 'Close Test panel' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).not.toHaveClass('cds--btn--sm');
    // Carbon footer: a real ButtonSet, buttons filling the footer edge-to-edge.
    const save = screen.getByRole('button', { name: 'Save' });
    expect(save).not.toHaveClass('cds--btn--sm');
    expect(save.closest('.afy-dock__form-actions')).not.toBeNull();
    expect(save.closest('.cds--btn-set')).not.toBeNull();
    expect(screen.getByRole('button', { name: 'Body action' })).not.toHaveClass('cds--btn--sm');

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('routes the secondary footer action to onSecondary when provided', () => {
    const onClose = vi.fn();
    const onSubmit = vi.fn((event: FormEvent) => event.preventDefault());
    const onSecondary = vi.fn();
    render(
      <DockedPanel
        heading="Walk-in"
        primaryLabel="Check in"
        secondaryLabel="Back to search"
        onClose={onClose}
        onSubmit={onSubmit}
        onSecondary={onSecondary}
      >
        <p>Body</p>
      </DockedPanel>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Back to search' }));
    expect(onSecondary).toHaveBeenCalledTimes(1);
    expect(onClose).not.toHaveBeenCalled();
  });

  it('keeps the footer to the Carbon button pair: Cancel left, primary right', () => {
    const onClose = vi.fn();
    render(
      <DockedPanel
        heading="Approval limits"
        primaryLabel="Save limit"
        onClose={onClose}
        onSubmit={vi.fn()}
      >
        <p>Body</p>
      </DockedPanel>,
    );

    const footer = document.querySelector('.afy-dock__form-actions');
    expect(footer).toHaveClass('cds--btn-set');
    const labels = footer
      ? Array.from(footer.querySelectorAll<HTMLButtonElement>('.cds--btn')).map((button) => button.textContent)
      : [];
    // Carbon modal-footer pair exactly — no extra buttons in the footer.
    expect(labels).toEqual(['Cancel', 'Save limit']);
  });

  it('toggles between the normal and expanded width in the header', () => {
    const onClose = vi.fn();
    render(
      <DockedPanel
        heading="New purchase order"
        ariaLabel="New purchase order"
        width="min(56rem, 100%)"
        expandedWidth="min(75rem, 100%)"
        expandable
        onClose={onClose}
      >
        <p>Body</p>
      </DockedPanel>,
    );

    const panel = screen.getByRole('dialog', { name: 'New purchase order' });
    expect(panel).toHaveStyle({ width: 'min(56rem, 100%)' });

    // The toggle sits beside the close button in the header.
    fireEvent.click(screen.getByRole('button', { name: 'Expand New purchase order' }));
    expect(panel).toHaveStyle({ width: 'min(75rem, 100%)' });
    expect(screen.getByRole('button', { name: 'Collapse New purchase order' })).toHaveAttribute(
      'aria-expanded',
      'true',
    );

    fireEvent.click(screen.getByRole('button', { name: 'Collapse New purchase order' }));
    expect(panel).toHaveStyle({ width: 'min(56rem, 100%)' });
  });

  it('hides the expand toggle for top/bottom docks', () => {
    render(
      <DockedPanel heading="Bottom drawer" placement="bottom" expandable onClose={vi.fn()}>
        <p>Body</p>
      </DockedPanel>,
    );

    expect(screen.queryByRole('button', { name: 'Expand Bottom drawer' })).not.toBeInTheDocument();
  });

  it('renders a full-width footer action on read-only panels', () => {
    const onClose = vi.fn();
    render(
      <DockedPanel
        heading="Walk-in"
        onClose={onClose}
        actions={
          <>
            <button type="button" className="cds--btn">Register</button>
            <button type="button" className="cds--btn">Add to basket</button>
          </>
        }
      >
        <p>Body</p>
      </DockedPanel>,
    );

    const register = screen.getByRole('button', { name: 'Register' });
    expect(register.closest('.afy-dock__actions')).not.toBeNull();
    expect(screen.getByRole('button', { name: 'Add to basket' }).closest('.afy-dock__actions')).not.toBeNull();
  });

  it.each([
    ['top', { top: '3rem', height: 'min(40vh, 24rem)' }],
    ['right', { top: '3rem', width: 'min(34rem, 100%)', right: '0px' }],
    ['bottom', { bottom: '0px', height: 'min(78vh, 48rem)' }],
    ['left', { top: '3rem', width: 'min(34rem, 100%)', left: '0px' }],
  ] as const)('supports %s placement', (placement, expectedStyle) => {
    render(
      <DockedPanel heading="Placed panel" placement={placement} onClose={vi.fn()}>
        <p>Body</p>
      </DockedPanel>,
    );

    const panel = screen.getByRole('dialog', { name: 'Placed panel' });
    expect(panel).toHaveClass('cds--layer__with-background', `afy-dock--${placement}`);
    expect(panel.className).toMatch(/cds--layer-(one|two|three)/);
    expect(panel).toHaveStyle(expectedStyle);
  });
});
