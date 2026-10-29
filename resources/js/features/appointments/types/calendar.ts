export interface AppointmentCalendarServiceCount {
  type: string;
  count: number;
}

export interface AppointmentCalendarDay {
  date: string;
  total: number;
  services: AppointmentCalendarServiceCount[];
}

export interface AppointmentDayItem {
  id: string;
  appointmentNumber: string;
  scheduledStart: string;
  scheduledEnd: string;
  status: string;
  reason: string | null;
  patient: {
    patientId: string;
    patientNumber: string;
    displayName: string;
  } | null;
  appointmentTypeName: string | null;
  resourceName: string | null;
}

export interface AppointmentCalendarPageProps {
  month: string;
  gridStart: string;
  days: AppointmentCalendarDay[];
  calendarError: boolean;
  view: 'day' | 'week' | 'month';
  weekIndex: number;
  appointmentTypeFilter: string | null;
  focusDate: string | null;
  selectedDay: string | null;
  appointments: AppointmentDayItem[];
  appointmentsError: boolean;
}