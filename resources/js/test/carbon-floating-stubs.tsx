import { createContext, useContext, useState, type MouseEventHandler, type ReactNode } from 'react';

const MenuCloseContext = createContext<() => void>(() => {});

function MenuItems({ children, onClose }: { children: ReactNode; onClose: () => void }): ReactNode {
  return (
    <div role="menu">
      <MenuCloseContext.Provider value={onClose}>{children}</MenuCloseContext.Provider>
    </div>
  );
}

export function TestCarbonMenuItem({
  disabled,
  label,
  onClick,
}: {
  disabled?: boolean;
  label: string;
  onClick?: MouseEventHandler<HTMLButtonElement>;
}): ReactNode {
  const closeMenu = useContext(MenuCloseContext);

  return (
    <button
      type="button"
      role="menuitem"
      aria-disabled={disabled || undefined}
      disabled={disabled}
      onClick={(event) => {
        onClick?.(event);
        closeMenu();
      }}
    >
      <svg aria-hidden="true" />
      {label}
    </button>
  );
}

export function TestCarbonComboButton({
  children,
  disabled,
  label,
  onClick,
}: {
  children: ReactNode;
  disabled?: boolean;
  label: string;
  onClick?: MouseEventHandler<HTMLButtonElement>;
}): ReactNode {
  const [isOpen, setIsOpen] = useState(false);

  return (
    <>
      <button type="button" disabled={disabled} onClick={onClick}>
        {label}
      </button>
      <button
        type="button"
        aria-label="Additional actions"
        aria-expanded={isOpen}
        disabled={disabled}
        onClick={() => setIsOpen(!isOpen)}
      >
        <svg aria-hidden="true" />
        Additional actions
      </button>
      {isOpen ? <MenuItems onClose={() => setIsOpen(false)}>{children}</MenuItems> : null}
    </>
  );
}

export function TestCarbonMenuButton({
  children,
  label,
}: {
  children: ReactNode;
  label: string;
}): ReactNode {
  const [isOpen, setIsOpen] = useState(false);

  return (
    <>
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={isOpen}
        onClick={() => setIsOpen(!isOpen)}
      >
        <svg aria-hidden="true" />
        {label}
      </button>
      {isOpen ? <MenuItems onClose={() => setIsOpen(false)}>{children}</MenuItems> : null}
    </>
  );
}

export function TestCarbonPopover({ children }: { children: ReactNode }): ReactNode {
  return <>{children}</>;
}

export function TestCarbonPopoverContent({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}): ReactNode {
  return <div className={className}>{children}</div>;
}
