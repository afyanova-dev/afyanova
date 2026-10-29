import { Tile } from '@carbon/react';
import type { ReactNode } from 'react';

interface SectionProps {
  title?: string;
  description?: string;
  children: ReactNode;
}

export function Section({ title, description, children }: SectionProps): ReactNode {
  return (
    <Tile className="afy-card afy-card--flow">
      {title ? <h2 className="afy-card__title">{title}</h2> : null}
      {description ? <p className="afy-card__description">{description}</p> : null}
      {children}
    </Tile>
  );
}
