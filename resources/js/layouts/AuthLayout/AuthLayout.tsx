import { usePage } from '@inertiajs/react';
import { InlineNotification } from '@carbon/react';
import type { ReactNode } from 'react';
import { HealthCross } from '@/icons';
import type { SharedProps } from '@/types';

/**
 * Carbon login pattern, centered-card direction (login-variants demo,
 * variant D): one canvas stage with a token-drawn texture, one raised
 * surface card carrying the brand lockup and the form. The panel keeps
 * the legacy `afyanova-auth-content` API so every auth screen's inner
 * composition keeps working unchanged.
 */
export function AuthLayout({ children, ariaLabel = 'Log in' }: { children: ReactNode; ariaLabel?: string }): ReactNode {
  const { props } = usePage<SharedProps>();
  const appName = props.app.name || 'AfyaNova';
  const { flash } = props;

  return (
    <main className="afy-auth">
      <a href="#auth-content" className="afyanova-skip-link">
        Skip to content
      </a>
      <div className="afy-auth__shell">
        <section
          id="auth-content"
          className="afy-auth__panel"
          tabIndex={-1}
          aria-label={ariaLabel}
        >
          <div className="afy-auth__brand-lockup">
            <span className="afy-auth__brand-mark" aria-hidden="true">
              <HealthCross size={24} />
            </span>
            <p className="afy-auth__brand-name">
              {appName}
              <small className="afy-auth__brand-meta">
                Clinical operations platform
              </small>
            </p>
          </div>
          <div className="afyanova-auth-content">
            <div className="afyanova-auth-notices" role="status" aria-live="polite">
              {flash.id && flash.status ? (
                <InlineNotification
                  key={`status-${flash.id}`}
                  kind="success"
                  title={flash.status}
                  lowContrast
                />
              ) : null}
              {flash.id && flash.error ? (
                <InlineNotification
                  key={`error-${flash.id}`}
                  kind="error"
                  title={flash.error}
                  lowContrast
                />
              ) : null}
              {flash.id && flash.info ? (
                <InlineNotification
                  key={`info-${flash.id}`}
                  kind="info"
                  title={flash.info}
                  lowContrast
                />
              ) : null}
            </div>
            {children}
          </div>
        </section>
      </div>
    </main>
  );
}
