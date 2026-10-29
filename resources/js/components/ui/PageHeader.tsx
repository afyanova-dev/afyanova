import type { ReactNode } from 'react';

interface PageHeaderProps {
  title: string;
  description?: string;
  actions?: ReactNode;
  badge?: ReactNode;
}

export function PageHeader({ title, description, actions, badge }: PageHeaderProps): ReactNode {
  return (
    <header className="afy-page__header">
      <div className="afy-page__heading">
        <h1 className="afy-page__title">
          {title}
          {badge ?? null}
        </h1>
        {description ? <p className="afy-page__description">{description}</p> : null}
      </div>
      {actions ? <div className="afy-page__actions">{actions}</div> : null}
    </header>
  );
}
