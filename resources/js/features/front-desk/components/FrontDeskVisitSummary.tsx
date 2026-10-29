import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { formatDateTimeInZone } from '@/lib/formatting';

/**
 * Minimal visit facts the roster rows already hold — no fetch, no
 * navigation, peek in place. Reading stays here; acting stays in the row
 * actions; the chart is only for the full patient story.
 */
export interface FrontDeskVisitSummary {
  number: string;
  type: string;
  status: string;
  arrivedAt: string | null;
  startedAt: string | null;
}

function readableLabel(value: string): string {
  return value
    .toLowerCase()
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((part) => part[0]!.toUpperCase() + part.slice(1))
    .join(' ');
}

/**
 * Visit summary peek for front-desk roster rows: the encounter number
 * opens a small summary panel with the visit facts, so triage never
 * loses board state (date/status filter, search, scroll).
 *
 * The panel portals to `document.body` with fixed positioning — rendering
 * it inside the table would stretch the table's scroll region and force
 * a horizontal scrollbar.
 */
export function FrontDeskVisitSummary({
  label,
  timezone,
  summary,
  children,
}: {
  /** Accessible name for the trigger, e.g. `Visit summary for ENC-0001`. */
  label: string;
  /** Facility zone for wall-clock display (backend prop, Rule 04). */
  timezone: string;
  summary: FrontDeskVisitSummary;
  /** Trigger content — the encounter number. */
  children: ReactNode;
}): ReactNode {
  const [open, setOpen] = useState(false);
  const anchorRef = useRef<HTMLSpanElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const [position, setPosition] = useState<{ left: number; top: number } | null>(null);

  useLayoutEffect(() => {
    if (!open) return;

    function place(): void {
      const anchor = anchorRef.current?.getBoundingClientRect();
      if (!anchor) return;
      const width = panelRef.current?.offsetWidth ?? 240;
      const height = panelRef.current?.offsetHeight ?? 200;
      const margin = 8;
      const left = Math.max(margin, Math.min(anchor.left, window.innerWidth - width - margin));
      let top = anchor.bottom + 4;
      if (top + height > window.innerHeight - margin) {
        top = Math.max(margin, anchor.top - height - 4);
      }
      setPosition({ left, top });
    }

    place();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);

    return () => {
      window.removeEventListener('resize', place);
      window.removeEventListener('scroll', place, true);
    };
  }, [open ]);

  // Dismiss on outside pointer-down or Escape.
  useEffect(() => {
    if (!open) return;

    function onPointerDown(event: MouseEvent): void {
      const target = event.target as Node | null;
      if (
        target
        && !anchorRef.current?.contains(target)
        && !panelRef.current?.contains(target)
      ) {
        setOpen(false);
      }
    }

    function onKeyDown(event: KeyboardEvent): void {
      if (event.key === 'Escape') setOpen(false);
    }

    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open ]);

  return (
    <>
      <span ref={anchorRef} className="front-desk-visit-summary__anchor">
        <button
          type="button"
          className="front-desk-visit-summary__trigger"
          aria-expanded={open}
          aria-label={label}
          onClick={() => setOpen((value) => !value)}
        >
          {children}
        </button>
      </span>
      {open ? createPortal(
        <div
          ref={panelRef}
          className="front-desk-visit-summary__panel"
          style={position ? { left: position.left, top: position.top } : { visibility: 'hidden' }}
        >
          <p className="front-desk-visit-summary__title">Visit summary</p>
          <dl className="front-desk-visit-summary__list">
            <div>
              <dt>Visit number</dt>
              <dd>{summary.number}</dd>
            </div>
            <div>
              <dt>Type</dt>
              <dd>{readableLabel(summary.type)}</dd>
            </div>
            <div>
              <dt>Status</dt>
              <dd>{readableLabel(summary.status)}</dd>
            </div>
            <div>
              <dt>Arrived</dt>
              <dd>{formatDateTimeInZone(summary.arrivedAt, timezone) ?? '—'}</dd>
            </div>
            {summary.startedAt ? (
              <div>
                <dt>Started</dt>
                <dd>{formatDateTimeInZone(summary.startedAt, timezone)}</dd>
              </div>
            ) : null}
          </dl>
        </div>,
        document.body,
      ) : null}
    </>
  );
}
