import { Tile } from '@carbon/react';
import type { ReactNode } from 'react';

interface StateProps {
  title: string;
  description?: string;
  action?: ReactNode;
}

export function EmptyState({ title, description, action }: StateProps): ReactNode {
  return (
    <Tile className="afy-card">
      <h3 className="afy-card__title">{title}</h3>
      {description ? <p className="afy-card__description">{description}</p> : null}
      {action ? <div className="afy-card__footer">{action}</div> : null}
    </Tile>
  );
}
