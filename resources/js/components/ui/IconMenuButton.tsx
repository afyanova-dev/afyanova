import { Button, Menu } from '@carbon/react';
import { useEffect, useId, useLayoutEffect, useRef, useState, type ElementType, type ReactNode } from 'react';

/**
 * MenuButton with a caller-chosen trigger icon.
 *
 * Carbon's `MenuButton` hardcodes `ChevronDown` as its trigger icon (no
 * `renderIcon` prop upstream), so banners that need a different affordance —
 * e.g. a three-dots overflow trigger that still shows its text label — use
 * this wrapper instead. It composes the same public Carbon parts `MenuButton`
 * itself is built from (`Button` trigger + `Menu` items) with the same a11y
 * wiring (`aria-haspopup`, `aria-expanded`, `aria-controls`) and the same
 * open/close semantics (toggle, select, Escape, blur, outside pointer).
 *
 * Positioning: Carbon's `Menu` portals to `document.body`, so this places the
 * open menu bottom-end to the trigger and keeps it there across scroll and
 * resize (the role floating-ui plays inside `MenuButton`).
 */
export function IconMenuButton({
  label,
  icon,
  kind = 'primary',
  size = 'lg',
  backgroundToken = 'layer',
  border = false,
  disabled = false,
  iconOnly = false,
  className,
  children,
}: {
  /** Visible text label rendered on the trigger button. */
  label: string;
  /** Trigger icon (e.g. three dots) rendered beside the label. */
  icon: ElementType;
  kind?: 'primary' | 'tertiary' | 'ghost';
  size?: 'xs' | 'sm' | 'md' | 'lg';
  backgroundToken?: 'layer' | 'background';
  border?: boolean;
  disabled?: boolean;
  /** Render an icon-only Carbon trigger while keeping its accessible label. */
  iconOnly?: boolean;
  className?: string;
  /** `MenuItem` entries rendered in the floating menu. */
  children?: ReactNode;
}): ReactNode {
  const [open, setOpen] = useState(false);
  const menuId = useId();
  const triggerRef = useRef<HTMLDivElement>(null);
  const menuRef = useRef<HTMLUListElement>(null);

  function close(): void {
    setOpen(false);
  }

  useLayoutEffect(() => {
    if (!open) return;
    const menu = menuRef.current;

    function place(): void {
      const anchor = triggerRef.current?.getBoundingClientRect();
      if (!anchor || !menu) return;
      menu.style.minWidth = `${anchor.width}px`;
      const { width, height } = menu.getBoundingClientRect();
      const margin = 8;
      const inlineStart = Math.max(margin, Math.min(anchor.right - width, window.innerWidth - width - margin));
      let blockStart = anchor.bottom + 4;
      if (blockStart + height > window.innerHeight - margin) {
        blockStart = Math.max(margin, anchor.top - height - 4);
      }
      menu.style.position = 'fixed';
      menu.style.insetInlineStart = `${inlineStart}px`;
      menu.style.insetBlockStart = `${blockStart}px`;
    }

    const frame = window.requestAnimationFrame(place);
    const resizeObserver = typeof ResizeObserver === 'undefined'
      ? null
      : new ResizeObserver(place);
    if (menu) resizeObserver?.observe(menu);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);

    return () => {
      window.cancelAnimationFrame(frame);
      resizeObserver?.disconnect();
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open ]);

  // Outside pointer-down closes; the Menu itself closes on select (via
  // onClose), Escape, and blur-away.
  useEffect(() => {
    if (!open) return;

    function onPointerDown(event: MouseEvent): void {
      const target = event.target as Node | null;
      if (
        target
        && !triggerRef.current?.contains(target)
        && !menuRef.current?.contains(target)
      ) {
        close();
      }
    }

    document.addEventListener('mousedown', onPointerDown);

    return () => {
      document.removeEventListener('mousedown', onPointerDown);
    };
  }, [open ]);

  return (
    <div ref={triggerRef} className={className}>
      <Button
        kind={kind}
        size={size}
        renderIcon={icon}
        disabled={disabled}
        hasIconOnly={iconOnly}
        iconDescription={iconOnly ? label : undefined}
        aria-label={iconOnly ? label : undefined}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? menuId : undefined}
        onClick={() => setOpen((value) => !value)}
      >
        {iconOnly ? null : label}
      </Button>
      <Menu
        id={menuId}
        label={label}
        size={size}
        open={open}
        onClose={close}
        ref={menuRef}
        legacyAutoalign={false}
        menuAlignment="bottom-end"
        backgroundToken={backgroundToken}
        border={border}
      >
        {children}
      </Menu>
    </div>
  );
}
