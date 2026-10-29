import { Link, router } from '@inertiajs/react';
import {
  Header,
  HeaderGlobalAction,
  HeaderGlobalBar,
  HeaderMenuButton,
  HeaderName,
  HeaderPanel,
  MenuItem,
  SkipToContent,
  Switcher,
  SwitcherItem,
} from '@carbon/react';
import { Checkmark, Logout, Moon, SettingsAdjust, Sun, Switcher as SwitcherIcon, UserAvatar, UserFollow, UserMultiple, UserSettings } from '@/icons';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { useCarbonTheme, type CarbonThemeName } from '@/app/CarbonThemeProvider';
import { IconMenuButton } from '@/components/ui';
import { workspaceIconNode } from './workspaceIcon';
import { FacilityPeopleQuickSearch, FacilitySwitcher, PatientQuickSearch, PlatformAdminQuickSearch } from '@/components/navigation';
import type { NavigationItem } from './navigation';

const themeOptions: { name: CarbonThemeName; label: string; icon: typeof Sun }[] = [
  { name: 'white', label: 'White', icon: Sun },
  { name: 'g10', label: 'Gray 10', icon: Sun },
  { name: 'g90', label: 'Gray 90', icon: Moon },
  { name: 'g100', label: 'Gray 100', icon: Moon },
];

interface AppHeaderProps {
  appName: string;
  userName: string | null;
  menuExpanded: boolean;
  onMenuClick: () => void;
  hasContextualNav: boolean;
  isPlatformSuperAdmin?: boolean;
  /** Icon-button fast path: permission-filtered record and creation tasks. */
  primaryLinks: NavigationItem[];
  /** All permitted workspaces (Carbon §6): rendered in the app-menu Switcher. */
  workspaces: NavigationItem[];
  /** Contextual destinations, including Admin sections while inside Admin. */
  appMenuItems: NavigationItem[];
}

/** Icon per primary task: browse records vs create a record. */
function primaryIcon(id: string): ReactNode {
  if (id === 'primary-register') return <UserFollow />;

  return <UserMultiple />;
}

/**
 * Global header (Carbon UI Shell).
 *
 * 1 Main menu — toggles contextual SideNav only, rendered when sidebar exists.
 * 2 Header name + facility context — app identity and active facility (left).
 * 3 Primary icon actions — permission-filtered patient tasks beside utilities.
 * 5 Utilities — expandable patient search, account.
 * 6 Switcher — 9-dot app menu for switching between permitted workspaces.
 */
export function AppHeader({
  appName,
  userName,
  menuExpanded,
  onMenuClick,
  hasContextualNav,
  isPlatformSuperAdmin = false,
  primaryLinks,
  workspaces,
  appMenuItems,
}: AppHeaderProps): ReactNode {
  const [switcherOpen, setSwitcherOpen] = useState(false);
  const { theme, setTheme } = useCarbonTheme();
  const homeHref = isPlatformSuperAdmin
    ? routes.platformAdmin.index
    : workspaces.length === 1 && workspaces[0]?.id === 'admin'
      ? routes.workspaces.admin
      : routes.dashboard;

  return (
    <Header aria-label={appName}>
      <SkipToContent />
      {hasContextualNav ? (
        <HeaderMenuButton
          aria-label={menuExpanded ? 'Close contextual navigation' : 'Open contextual navigation'}
          onClick={onMenuClick}
          isActive={menuExpanded}
        />
      ) : null}
      <HeaderName as={Link} href={homeHref} prefix="">
        {appName}
      </HeaderName>
      <FacilitySwitcher />
      <HeaderGlobalBar>
        {isPlatformSuperAdmin ? <PlatformAdminQuickSearch /> : <PatientQuickSearch />}
        {!isPlatformSuperAdmin ? <FacilityPeopleQuickSearch /> : null}
        {primaryLinks.map((link) => (
          <HeaderGlobalAction
            key={link.id}
            aria-label={link.label}
            tooltipAlignment="center"
            isActive={link.active}
            onClick={() => router.visit(link.href)}
          >
            {primaryIcon(link.id)}
          </HeaderGlobalAction>
        ))}
        {userName ? (
          <IconMenuButton
            className="afyanova-profile-menu"
            label={`Account menu for ${userName}`}
            icon={UserAvatar}
            kind="ghost"
            size="lg"
            iconOnly
            backgroundToken="layer"
          >
            <MenuItem
              label={userName}
              renderIcon={UserAvatar}
              disabled
            />
            <MenuItem
              label="Appearance"
              renderIcon={SettingsAdjust}
            >
              {themeOptions.map(({ name, label, icon: Icon }) => (
                <MenuItem
                  key={name}
                  label={label}
                  renderIcon={theme === name ? Checkmark : Icon}
                  aria-checked={theme === name}
                  onClick={() => setTheme(name)}
                />
              ))}
            </MenuItem>
            <MenuItem
              label="My sessions"
              renderIcon={UserSettings}
              onClick={() => router.visit(routes.sessions.index)}
            />
            <MenuItem
              label="Log out"
              renderIcon={Logout}
              kind="danger"
              onClick={() => router.post(routes.logout)}
            />
          </IconMenuButton>
        ) : null}
        {appMenuItems.length > 1 ? (
          <HeaderGlobalAction
            aria-label="App menu"
            tooltipAlignment="end"
            isActive={switcherOpen}
            onClick={() => setSwitcherOpen((open) => !open)}
          >
            <SwitcherIcon />
          </HeaderGlobalAction>
        ) : null}
      </HeaderGlobalBar>
      {appMenuItems.length > 1 ? (
        <HeaderPanel
          expanded={switcherOpen}
          onHeaderPanelFocus={() => setSwitcherOpen(false)}
        >
          <Switcher aria-label="App navigation" expanded={switcherOpen}>
            {appMenuItems.map((workspace) => (
              <SwitcherItem
                key={workspace.id}
                aria-label={workspace.label}
                href={workspace.href}
                isSelected={workspace.active}
                onClick={(event) => {
                  event.preventDefault();
                  setSwitcherOpen(false);
                  router.visit(workspace.href);
                }}
              >
                <span className="afyanova-switcher-item">
                  <span className="afyanova-switcher-item__icon" aria-hidden="true">
                    {workspaceIconNode(workspace.id)}
                  </span>
                  {workspace.label}
                </span>
              </SwitcherItem>
            ))}
          </Switcher>
        </HeaderPanel>
      ) : null}
    </Header>
  );
}
