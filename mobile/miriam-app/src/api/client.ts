import type {
  AgendaItem,
  ChatResponse,
  Dashboard,
  DevelopmentStatus,
  MedicationStatus,
  Reminder,
  User,
} from '../types';

declare const process: {
  env: Record<string, string | undefined>;
};

const configuredBaseUrl = process.env.EXPO_PUBLIC_MIRIAM_API_BASE_URL;
const defaultBaseUrl = 'https://friday.paulstechnologies.com';

export const apiBaseUrl = (configuredBaseUrl || defaultBaseUrl).replace(/\/$/, '');

type RequestOptions = RequestInit & {
  token?: string | null;
};

async function requestJson<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');

  if (options.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  if (options.token) {
    headers.set('Authorization', `Bearer ${options.token}`);
  }

  const response = await fetch(`${apiBaseUrl}${path}`, {
    ...options,
    headers,
  });

  const text = await response.text();
  const data = text ? JSON.parse(text) : {};

  if (!response.ok) {
    const message = data?.message || 'Miriam request failed.';
    throw new Error(message);
  }

  return data as T;
}

export function login(email: string, password: string, deviceName: string) {
  return requestJson<{ token: string; token_type: string; user: User }>('/api/mobile/login', {
    method: 'POST',
    body: JSON.stringify({ email, password, device_name: deviceName }),
  });
}

export function createMobileApi(token: string | null) {
  return {
    me: () => requestJson<{ user: User; dashboard: Dashboard }>('/api/mobile/me', { token }),
    logout: () => requestJson<{ ok: boolean }>('/api/mobile/logout', { method: 'POST', token }),
    chat: (message: string) =>
      requestJson<ChatResponse>('/api/mobile/miriam/chat', {
        method: 'POST',
        token,
        body: JSON.stringify({ message }),
      }),
    reminders: () => requestJson<{ data: Reminder[] }>('/api/mobile/reminders', { token }),
    reminderAction: (id: number, action: 'done' | 'snooze' | 'cancel') =>
      requestJson<{ data: Reminder }>(`/api/mobile/reminders/${id}/${action}`, {
        method: 'POST',
        token,
      }),
    medicationStatus: () => requestJson<MedicationStatus>('/api/mobile/medication/status', { token }),
    medicationAction: (
      doseLogId: number,
      action: 'taken' | 'snooze' | 'skip',
      payload: Record<string, unknown> = {},
    ) =>
      requestJson<{ data: unknown }>(`/api/mobile/medication/${doseLogId}/${action}`, {
        method: 'POST',
        token,
        body: JSON.stringify(payload),
      }),
    agenda: (period: 'today' | 'tomorrow' | 'upcoming') =>
      requestJson<{ period: string; items: AgendaItem[] }>(`/api/mobile/agenda/${period}`, { token }),
    developmentStatus: () =>
      requestJson<DevelopmentStatus>('/api/mobile/development/status', { token }),
  };
}
