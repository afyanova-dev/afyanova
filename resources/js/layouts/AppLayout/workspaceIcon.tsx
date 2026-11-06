import type { ComponentType, ReactNode } from 'react';
import {
  Add,
  Box,
  Calendar,
  Checkmark,
  Dashboard,
  Document,
  Flow,
  HealthCross,
  List,
  Microscope,
  Money,
  Notification,
  Pills,
  Send,
  SettingsAdjust,
  Task,
  Time,
  UserAvatar,
  UserMultiple,
  UserSettings,
} from '@/icons';

/**
 * Icon per workspace id: one glyph language for every surface that lists
 * workspaces (app-menu Switcher, dashboard tiles). Returns the icon
 * *component* (not an element) so callers can hand it straight to Carbon's
 * `renderIcon` (e.g. `ClickableTile`). Falls back to undefined for unmapped
 * ids — decorative, labels carry the meaning.
 */
export function workspaceIcon(id: string): ComponentType | undefined {
  switch (id) {
    case 'dashboard':
    case 'platform-overview':
      return Dashboard;
    case 'admin':
    case 'admin-overview':
      return UserSettings;
    case 'platform-tenants':
    case 'admin-organization':
    case 'hrStaff':
    case 'hr-directory':
    case 'patients':
      return UserMultiple;
    case 'hr-onboarding':
      return Task;
    case 'platform-super-admins':
      return UserSettings;
    case 'hr-add-staff':
      return Add;
    case 'admin-audit':
    case 'platform-audit':
      return Document;
    case 'admin-role-promotions':
      return UserSettings;
    case 'hr-positions':
      return SettingsAdjust;
    case 'hr-access-requests':
      return UserSettings;
    case 'hr-account-invitations':
      return Send;
    case 'admin-staff-assignments':
    case 'hr-assignment-requests':
      return Checkmark;
    case 'appointments':
      return Calendar;
    case 'reception':
      return Time;
    case 'patientFlow':
      return Flow;
    case 'serviceQueues':
      return List;
    case 'nurseStation':
      return UserAvatar;
    case 'clinician':
      return UserSettings;
    case 'clinician-diagnosis-catalog':
    case 'laboratoryExecution':
      return HealthCross;
    case 'laboratory':
      return Microscope;
    case 'attention':
      return Notification;
    case 'billing':
      return Money;
    case 'inventory':
      return Box;
    case 'laboratoryCatalogEditors':
      return UserSettings;
    case 'procurement':
      return Task;
    case 'pharmacy':
      return Pills;
    default:
      return undefined;
  }
}

/** Render helper for surfaces that need an element instead of a component. */
export function workspaceIconNode(id: string): ReactNode {
  const Icon = workspaceIcon(id);

  return Icon ? <Icon /> : null;
}
