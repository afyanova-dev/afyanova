import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export interface StatisticsCardItem {
  id: string;
  label: string;
  value: ReactNode;
  detail?: ReactNode;
  /** Optional Carbon icon glyph above the label (`.afy-stat__icon`). */
  icon?: ReactNode;
  /**
   * Optional trend caption next to the value (`.afy-stat__delta`), e.g.
   * an `ArrowUpRight` glyph plus delta text.
   */
  delta?: ReactNode;
  deltaTone?: 'positive' | 'negative' | 'information';
  /**
   * Optional colored top-accent border tone. Defaults to the brand link
   * color when omitted. Differentiates KPI cards from workspace tiles.
   */
  borderTone?: 'positive' | 'caution' | 'negative' | 'information';
  href?: string;
  ariaLabel?: string;
  selected?: boolean;
  actionLabel?: string;
  preserveScroll?: boolean;
  preserveState?: boolean;
  only?: string[];
  prefetch?: 'hover' | 'mount';
}

interface StatisticsCardsProps {
  cards: StatisticsCardItem[];
  label: string;
  className?: string;
  /**
   * `connected` (default) joins the tiles into one segmented KPI band on wide
   * screens (shared surface, hairline dividers) and falls back to separate
   * cards on narrower viewports. Use `separate` only when a surface must keep
   * detached cards at every width.
   */
  variant?: 'connected' | 'separate';
}

export function StatisticsCards({
  cards,
  label,
  className,
  variant = 'connected',
}: StatisticsCardsProps): ReactNode {
  return (
    <section
      className={[
        'afy-stats',
        variant === 'separate' ? '' : 'afy-stats--connected',
        className,
      ].filter(Boolean).join(' ')}
      role="region"
      aria-label={label}
    >
      {cards.map((card) => {
        const content = (
          <>
            <span className="afy-stat__top">
              {card.icon ? (
                <span className="afy-stat__icon" aria-hidden="true">
                  {card.icon}
                </span>
              ) : null}
              <span className="afy-stat__label">{card.label}</span>
            </span>
            <strong className="afy-stat__value">{card.value}</strong>
            {card.delta !== undefined ? (
              <span
                className={[
                  'afy-stat__delta',
                  card.deltaTone ? `afy-stat__delta--${card.deltaTone}` : '',
                ].filter(Boolean).join(' ')}
              >
                {card.delta}
              </span>
            ) : null}
            {card.detail !== undefined ? (
              <span className="afy-stat__detail">{card.detail}</span>
            ) : null}
            {card.actionLabel ? (
              <span className="afy-stat__action">{card.actionLabel}</span>
            ) : null}
          </>
        );
        const classNames = [
          'afy-stat',
          card.borderTone ? `afy-stat--border-${card.borderTone}` : '',
          card.href ? 'afy-stat--linked' : '',
          card.selected ? 'afy-stat--selected' : '',
        ].filter(Boolean).join(' ');

        return card.href ? (
          <Link
            key={card.id}
            className={classNames}
            href={card.href}
            aria-label={card.ariaLabel}
            aria-current={card.selected ? true : undefined}
            preserveScroll={card.preserveScroll}
            preserveState={card.preserveState}
            only={card.only}
            prefetch={card.prefetch}
          >
            {content}
          </Link>
        ) : (
          <article key={card.id} className={classNames}>
            {content}
          </article>
        );
      })}
    </section>
  );
}
