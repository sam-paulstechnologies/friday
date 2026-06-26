export type User = {
  id: number;
  name: string;
  email: string;
};

export type Dashboard = {
  today_summary: {
    date: string;
    pending_reminders: number;
    medication_pending: number;
    active_development_jobs: number;
  };
  next_reminders: Reminder[];
  medication: MedicationStatus;
  shortcuts: Record<string, string>;
};

export type Reminder = {
  id: number;
  title: string;
  status: string;
  due_at: string | null;
  category?: string | null;
};

export type MedicationRoutineItem = {
  schedule_id: number | null;
  label: string;
  dose_key: string;
  time: string | null;
  medications: string[];
};

export type MedicationDose = {
  id: number;
  dose_key: string;
  label: string;
  status: string;
  scheduled_for: string | null;
  acknowledged_at: string | null;
};

export type MedicationStatus = {
  timezone: string;
  routine: MedicationRoutineItem[];
  today: MedicationDose[];
};

export type AgendaItem = {
  type: 'calendar' | 'reminder';
  id: number | string;
  title: string;
  starts_at: string | null;
  ends_at?: string | null;
  status?: string | null;
  source?: string;
};

export type DevelopmentStatus = {
  active_jobs: Array<Record<string, unknown>>;
  recent_ledger: Array<Record<string, unknown>>;
  needs_attention: Array<Record<string, unknown>>;
};

export type ChatResponse = {
  reply: string;
  intent?: string;
  data?: unknown;
};
