import { usePage } from '@inertiajs/react';
import { Content, HeaderContainer, ToastNotification } from '@carbon/react';
import type { ReactNode } from 'react';
import type { SharedProps } from '@/types';
import { useCan } from '@/lib/authorization';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { AppHeader } from './Header';
import { AppSideNav } from './SideNav';
import { workspaceNavigation, type NavigationItem } from './navigation';

const FLASH_TITLE_MAX_LENGTH = 72;

export function shortNotificationTitle(message: string): string {
  const normalized = message.trim().replace(/\s+/g, ' ');
  const firstSentence = normalized.match(/^.*?[.!?](?=\s|$)/)?.[0] ?? normalized;
  if (firstSentence.length <= FLASH_TITLE_MAX_LENGTH) return firstSentence;

  const shortened = firstSentence
    .slice(0, FLASH_TITLE_MAX_LENGTH - 1)
    .replace(/\s+\S*$/, '')
    .trimEnd();

  return `${shortened}…`;
}

function FlashNotification({
  id,
  kind,
  message,
}: {
  id: string;
  kind: 'success' | 'error' | 'info';
  message: string;
}): ReactNode {
  const title = shortNotificationTitle(message);

  return (
    <div className="afyanova-flash-item">
      <ToastNotification
        className="afyanova-flash-notification"
        key={`${kind}-${id}`}
        kind={kind}
        title={title}
        lowContrast
        role={kind === 'error' ? 'alert' : 'status'}
      />
      {title !== message.trim().replace(/\s+/g, ' ') ? (
        <details className="afyanova-flash-details">
          <summary>More details</summary>
          <p>{message}</p>
        </details>
      ) : null}
    </div>
  );
}

function FlashMessages({ flash }: { flash: SharedProps['flash'] }): ReactNode {
  if (!flash.id) return null;

  return (
    <div className="afyanova-flash-messages" aria-live="polite">
      {flash.status ? (
        <FlashNotification id={flash.id} kind="success" message={flash.status} />
      ) : null}
      {flash.error ? (
        <FlashNotification id={flash.id} kind="error" message={flash.error} />
      ) : null}
      {flash.info ? (
        <FlashNotification id={flash.id} kind="info" message={flash.info} />
      ) : null}
    </div>
  );
}

export interface AppLayoutProps {
  children: ReactNode;
  sidebarContent?: ReactNode;
  activeSidebarItemId?: string;
  onSidebarItemClick?: (item: NavigationItem, event: React.MouseEvent) => void;
}

function AppShell({
  children,
  sidebarContent,
  activeSidebarItemId,
  onSidebarItemClick,
}: AppLayoutProps): ReactNode {
  const { props, url } = usePage<SharedProps>();
  const can = useCan();
  const navigation = workspaceNavigation(url, can, props.auth.isPlatformSuperAdmin);
  const isDesktop = useMediaQuery('(min-width: 66rem)');
  const appName = props.app.name || 'AfyaNova';

  return (
    <>
      <HeaderContainer
        key={isDesktop ? 'desktop-shell' : 'mobile-shell'}
        isSideNavExpanded={isDesktop}
        render={({ isSideNavExpanded, onClickSideNavExpand }) => (
          <>
            <AppHeader
              appName={appName}
              userName={props.auth.user?.name ?? null}
              menuExpanded={isSideNavExpanded}
              onMenuClick={onClickSideNavExpand}
              hasContextualNav={navigation.sidebar.length > 0 || Boolean(sidebarContent)}
              primaryLinks={navigation.primaryLinks}
              workspaces={navigation.workspaces}
              appMenuItems={navigation.appMenuItems}
              isPlatformSuperAdmin={props.auth.isPlatformSuperAdmin}
            />
            <AppSideNav
              expanded={isSideNavExpanded}
              isDesktop={isDesktop}
              workspace={navigation.activeWorkspace}
              items={navigation.sidebar}
              activeItemId={activeSidebarItemId}
              onItemClick={onSidebarItemClick}
              contextualContent={sidebarContent}
              onNavigate={() => {
                if (!isDesktop && isSideNavExpanded) onClickSideNavExpand();
              }}
            />
          </>
        )}
      />
      <Content id="main-content">
        <FlashMessages flash={props.flash} />
        {children}
      </Content>
    </>
  );
}

export function AppLayout({
  children,
  sidebarContent,
  activeSidebarItemId,
  onSidebarItemClick,
}: AppLayoutProps): ReactNode {
  return (
    <AppShell
      sidebarContent={sidebarContent}
      activeSidebarItemId={activeSidebarItemId}
      onSidebarItemClick={onSidebarItemClick}
    >
      {children}
    </AppShell>
  );
}
