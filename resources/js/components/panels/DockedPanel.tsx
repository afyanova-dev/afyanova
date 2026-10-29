import { Button, ButtonSet, IconButton, Layer } from '@carbon/react';
import { Close, Maximize, Minimize } from '@carbon/icons-react';
import { useEffect, useState, type CSSProperties, type FormEvent, type ReactNode } from 'react';

export type DockedPanelPlacement = 'top' | 'right' | 'bottom' | 'left';

export interface DockedPanelProps {
  heading: string;
  ariaLabel?: string;
  description?: ReactNode;
  placement?: DockedPanelPlacement;
  headerActions?: ReactNode;
  primaryLabel?: string;
  primaryDisabled?: boolean;
  secondaryLabel?: string;
  footerVisible?: boolean;
  danger?: boolean;
  hidden?: boolean;
  offsetBlockStart?: string;
  offsetBlockEnd?: string;
  offsetInlineStart?: string;
  offsetInlineEnd?: string;
  width?: string;
  /** Panel width while expanded; used when `expandable` is on a side dock. */
  expandedWidth?: string;
  /** Show a Maximize/Minimize toggle in the header (side docks only). */
  expandable?: boolean;
  height?: string;
  contentClassName?: string;
  /** Disable the browser's native validation so the caller owns messaging. */
  noValidate?: boolean;
  onClose: () => void;
  onSubmit?: (event: FormEvent) => void;
  onSecondary?: () => void;
  actions?: ReactNode;
  children: ReactNode;
}

/**
 * Shared Carbon-layered panel docked to any viewport edge. The caller owns
 * the content and lifecycle; this component provides the common shell.
 */
export function DockedPanel({
  heading,
  ariaLabel = heading,
  description,
  placement = 'right',
  headerActions,
  primaryLabel,
  primaryDisabled = false,
  secondaryLabel,
  footerVisible = true,
  danger = false,
  hidden = false,
  offsetBlockStart = '3rem',
  offsetBlockEnd = '0px',
  offsetInlineStart = '0px',
  offsetInlineEnd = '0px',
  width,
  expandedWidth,
  expandable = false,
  height,
  contentClassName,
  noValidate = false,
  onClose,
  onSubmit,
  onSecondary,
  actions,
  children,
}: DockedPanelProps): ReactNode {
  const closeLabel = secondaryLabel ?? (onSubmit ? 'Cancel' : 'Close');
  const sideDocked = placement === 'right' || placement === 'left';
  const [expanded, setExpanded] = useState(false);
  const effectiveWidth = expanded ? (expandedWidth ?? 'min(75rem, 100%)') : width;
  const style: CSSProperties = {
    display: hidden ? 'none' : 'flex',
  };

  if (placement === 'right' || placement === 'left') {
    Object.assign(style, {
      top: offsetBlockStart,
      bottom: offsetBlockEnd,
      width: effectiveWidth ?? 'min(34rem, 100%)',
      ...(placement === 'right' ? { right: offsetInlineEnd } : { left: offsetInlineStart }),
    });
  } else {
    Object.assign(style, {
      height: height ?? (placement === 'bottom' ? 'min(78vh, 48rem)' : 'min(40vh, 24rem)'),
      ...(placement === 'top' ? { top: offsetBlockStart } : { bottom: offsetBlockEnd }),
      left: offsetInlineStart,
      right: offsetInlineEnd,
    });
  }

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent): void {
      if (event.key === 'Escape') onClose();
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [onClose]);

  // A panel kept mounted but hidden (Suppliers-style panels) reopens at its
  // normal width; mounted-on-demand panels restart from a fresh instance.
  useEffect(() => {
    if (hidden) setExpanded(false);
  }, [hidden]);

  return (
    <Layer
      as="aside"
      level={1}
      withBackground
      role="dialog"
      aria-modal="false"
      aria-label={ariaLabel}
      aria-hidden={hidden}
      className={`afy-dock afy-dock--${placement}`}
      style={style}
    >
      <header className="afy-dock__header">
        <div className="afy-dock__heading-group">
          <h2 className="afy-dock__title">{heading}</h2>
          {description ? <div className="afy-dock__description">{description}</div> : null}
        </div>
        <div className="afy-dock__header-actions">
          {headerActions}
          {expandable && sideDocked ? (
            <IconButton
              kind="ghost"
              size="sm"
              align="bottom"
              aria-expanded={expanded}
              label={expanded ? `Collapse ${ariaLabel}` : `Expand ${ariaLabel}`}
              onClick={() => setExpanded((current) => !current)}
            >
              {expanded ? <Minimize /> : <Maximize />}
            </IconButton>
          ) : null}
          <IconButton kind="ghost" size="sm" align="bottom" label={`Close ${ariaLabel}`} onClick={onClose}>
            <Close />
          </IconButton>
        </div>
      </header>
      {onSubmit ? (
        <form className="afy-dock__form" onSubmit={onSubmit} noValidate={noValidate}>
          <div className={`afy-dock__content${contentClassName ? ` ${contentClassName}` : ''}`}>
            {children}
          </div>
          {footerVisible ? (
            <ButtonSet className="afy-dock__form-actions">
              <Button size="lg" kind="secondary" type="button" onClick={onSecondary ?? onClose}>
                {closeLabel}
              </Button>
              {primaryLabel ? (
                <Button size="lg" kind={danger ? 'danger' : 'primary'} type="submit" disabled={primaryDisabled}>
                  {primaryLabel}
                </Button>
              ) : null}
            </ButtonSet>
          ) : actions ? <div className="afy-dock__actions">{actions}</div> : null}
        </form>
      ) : (
        <div className="afy-dock__body">
          <div className={`afy-dock__content${contentClassName ? ` ${contentClassName}` : ''}`}>
            {children}
          </div>
          {actions ? <div className="afy-dock__actions">{actions}</div> : null}
        </div>
      )}
    </Layer>
  );
}
