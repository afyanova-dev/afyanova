import { MenuItem } from '@carbon/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { OverflowMenuVertical } from '@/icons';
import { IconMenuButton } from './IconMenuButton';

beforeEach(() => {
  globalThis.ResizeObserver ??= class {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
});

function openMenu(onPick: () => void = () => {}): void {
  render(
    <IconMenuButton label="Actions" icon={OverflowMenuVertical}>
      <MenuItem label="Edit patient details" onClick={onPick} />
    </IconMenuButton>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
}

describe('IconMenuButton', () => {
  it('renders a labelled trigger and positions the menu after it is laid out', async () => {
    openMenu();

    const trigger = screen.getByRole('button', { name: 'Actions' });
    expect(trigger).toHaveTextContent('Actions');
    expect(trigger.querySelector('svg')).toBeInTheDocument();

    expect(screen.getByRole('menuitem', { name: 'Edit patient details' })).toBeInTheDocument();
    const menu = document.querySelector('.cds--menu--open') as HTMLElement | null;
    await waitFor(() => {
      expect(menu?.style.position).toBe('fixed');
      expect(menu?.style.insetInlineStart).not.toBe('');
      expect(menu?.style.insetBlockStart).not.toBe('');
    });
  });

  it('runs the item action and closes the menu on select', () => {
    const onPick = vi.fn();
    openMenu(onPick);

    fireEvent.click(screen.getByRole('menuitem', { name: 'Edit patient details' }));

    expect(onPick).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('menuitem', { name: 'Edit patient details' })).not.toBeInTheDocument();
  });

  it('closes the menu on Escape', () => {
    openMenu();

    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });

    expect(screen.queryByRole('menuitem', { name: 'Edit patient details' })).not.toBeInTheDocument();
  });
});
