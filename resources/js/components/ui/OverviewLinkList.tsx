import { ClickableTile, Tag } from '@carbon/react';
import { Link, router } from '@inertiajs/react';
import type { ComponentType, KeyboardEvent, MouseEvent, ReactNode } from 'react';
import { ArrowRight } from '@/icons';

type NavigationEvent = MouseEvent<HTMLAnchorElement> | KeyboardEvent<HTMLAnchorElement>;

/**
 * Carbon's `ClickableTile` renders a plain `<a>`, so left clicks would trigger
 * a full page reload. Keep the real `href` (middle-click, new tab, a11y) but
 * hand unmodified primary clicks to Inertia.
 */
function navigateWithInertia(event: NavigationEvent, href: string): void {
  if (!('button' in event)) {
    return;
  }

  if (
    event.defaultPrevented
    || event.button !== 0
    || event.metaKey
    || event.ctrlKey
    || event.shiftKey
    || event.altKey
  ) {
    return;
  }

  event.preventDefault();
  router.visit(href);
}

export interface OverviewLinkItem {
  id: string;
  /** Omit for a disabled status tile that has no destination. */
  href?: string;
  title: string;
  description?: string;
  status?: string;
  /** Renders a non-navigable `ClickableTile` (for example, pending access). */
  disabled?: boolean;
  /** Carbon icon component rendered inside `ClickableTile` via `renderIcon`. */
  renderIcon?: ComponentType;
}

interface OverviewLinkListProps {
  title: string;
  items: OverviewLinkItem[];
  emptyMessage: string;
  eyebrow?: string;
  action?: ReactNode;
  className?: string;
  /**
   * `tiles` (default) renders Carbon `ClickableTile` launcher tiles — the
   * modern EMR app-launcher language. `rows` keeps the legacy vertical
   * list (raised panel with hairline dividers) for pages that need it.
   */
  layout?: 'tiles' | 'rows';
}

export function OverviewLinkList({
  title,
  items,
  emptyMessage,
  eyebrow,
  action,
  className,
  layout = 'tiles',
}: OverviewLinkListProps): ReactNode {
  const headingId = `afy-link-list-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
  const tiles = layout === 'tiles';

  return (
    <section
      className={['afy-link-list', tiles ? 'afy-link-list--tiles' : '', className].filter(Boolean).join(' ')}
      aria-labelledby={headingId}
    >
      <header className="afy-link-list__header">
        <div className="afy-link-list__heading">
          {eyebrow ? <p className="afy-eyebrow">{eyebrow}</p> : null}
          <h2 id={headingId} className="afy-link-list__title">{title}</h2>
        </div>
        {action}
      </header>
      {items.length === 0 ? (
        <p className="afy-link-list__empty">{emptyMessage}</p>
      ) : tiles ? (
        <ul className="afy-link-list__items">
          {items.map((item) => {
            const href = item.href;

            return (
              <li key={item.id}>
                <ClickableTile
                  href={href}
                  disabled={item.disabled}
                  className={item.disabled ? 'afy-link-list__tile--pending' : undefined}
                  renderIcon={item.renderIcon}
                  onClick={href && !item.disabled
                    ? (event) => navigateWithInertia(event, href)
                    : undefined}
                >
                  {item.description || item.status ? (
                    <>
                      <span className="afy-link-list__tile-title">{item.title}</span>
                      {item.description ? (
                        <span className="afy-link-list__tile-description">{item.description}</span>
                      ) : null}
                      {item.status ? (
                        <Tag type="blue" className="afy-link-list__tile-status">{item.status}</Tag>
                      ) : null}
                    </>
                  ) : item.title}
                </ClickableTile>
              </li>
            );
          })}
        </ul>
      ) : (
        <ul className="afy-link-list__items">
          {items.map((item) => {
            const Icon = item.renderIcon;
            const body = (
              <>
                {Icon ? (
                  <span className="afy-icon" aria-hidden="true">
                    <Icon />
                  </span>
                ) : null}
                <span className="afy-link-list__label">
                  {item.title}
                  {item.description ? <small className="afy-link-list__description">{item.description}</small> : null}
                </span>
                {item.status ? (
                  <span className="afy-link-list__end">{item.status}</span>
                ) : item.disabled || !item.href ? null : (
                  <ArrowRight size={16} aria-hidden="true" />
                )}
              </>
            );

            return (
              <li key={item.id}>
                {item.href && !item.disabled ? (
                  <Link href={item.href} className="afy-link-list__link">
                    {body}
                  </Link>
                ) : (
                  <span className="afy-link-list__link afy-link-list__link--disabled" aria-disabled="true">
                    {body}
                  </span>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
