/**
 * Rotas que o app Meu Dia usa na API REST do Connect ERP (/api/v1).
 *
 * - Sessão do aparelho da equipe (ADR 0660): POST /team/app-sessions (+ /verify para o 2FA), DELETE /team/app-sessions/current,
 *   GET /team/me e POST/DELETE /team/push-devices com app "meu-dia".
 * - Meu Dia (ADR 0630 e 0658): GET /my-day, GET /my-day/overdue, GET /my-day/team, POST /my-day/events e
 *   POST /my-day/events/{id}/status. As regras são as mesmas da tela /meu-dia do ERP: cada pessoa vê o próprio dia e
 *   só quem tem "ver a equipe" (erp.myday.team) vê o dia de outra pessoa ou marca tarefa para ela.
 * Datas AAAA-MM-DD, horas HH:MM.
 */
import type { ApiClient } from './client';

export type Session = { token: string; expires_at: string; idle_days: number };
export type LoginResult = { kind: 'ok'; session: Session } | { kind: 'twoFactor'; challenge: string };

export type Me = {
  id: number;
  name: string;
  email: string;
  role?: { slug: string; name: string } | null;
  can_see_team: boolean;
  can_attend_sac?: boolean;
};

export type Person = { id: number; name: string };

export type EventType = 'meeting' | 'task' | 'deadline' | 'support';
export type EventStatus = 'scheduled' | 'completed' | 'cancelled';

export type DayEvent = {
  id: number;
  title: string;
  description: string;
  starts_at: string;
  ends_at: string | null;
  start: string;
  end: string | null;
  minutes: number;
  type: EventType;
  type_label: string;
  status: EventStatus;
  condominium_id: number | null;
  condominium: string | null;
  ticket_id: number | null;
  protocol: string | null;
  /** Dia passou e ainda está marcado (ADR 0658). */
  overdue?: boolean;
};

export type Ticket = {
  id: number;
  protocol: string;
  subject: string;
  priority: string;
  priority_label: string;
  tag: string;
  status: string;
  status_label: string;
  category: string;
  due_at: string;
  due_on: string;
  overdue: boolean;
  due_today: boolean;
  condominium_id?: number | null;
  condominium?: string | null;
};

export type Visit = {
  plan_id: number;
  condominium_id: number;
  condominium: string;
  address: string;
  template: string;
  frequency_label: string;
  due_on: string;
  overdue: boolean;
};

export type Deadline = { id: number; title: string; date: string; days: number; label: string; condominium_id?: number; condominium?: string };

export type StripDay = { date: string; weekday: string; day: number; count: number; today: boolean; selected: boolean; overdue?: number };

export type OverdueKind = 'event' | 'ticket' | 'visit' | 'deadline';

/** Tarefa que ficou para trás e foi levada para hoje até fechar (ADR 0658). */
export type OverdueItem = {
  kind: OverdueKind;
  id: number;
  title: string;
  due_on: string;
  days_late: number;
  condominium_id?: number | null;
  condominium?: string | null;
  ticket_id?: number | null;
  protocol?: string | null;
  status?: string;
};

export type Day = {
  date: string;
  today: string;
  person: Person;
  summary: {
    commitments: number;
    commitment_minutes: number;
    done: number;
    to_schedule: number;
    waiting: number;
    overdue: number;
    overdue_oldest?: string | null;
    unassigned?: number;
    week_visits: number;
    week_condominiums: number;
  };
  week: StripDay[];
  strip: StripDay[];
  events: DayEvent[];
  week_events: DayEvent[];
  day_visits: Visit[];
  day_deadlines: Ticket[];
  to_schedule: Ticket[];
  waiting: Ticket[];
  visits: Visit[];
  deadlines: Deadline[];
  overdue?: OverdueItem[];
  unassigned?: Ticket[];
};

export type Overdue = { count: number; oldest: string | null; items: OverdueItem[] };

export type NewTask = {
  title: string;
  date: string;
  start: string;
  end?: string;
  type: EventType;
  description?: string;
  assigned_to?: number;
  ticket_id?: number;
};

export function teamApi(client: ApiClient) {
  return {
    async login(login: string, password: string, deviceName: string): Promise<LoginResult> {
      const data = await client.post<Session & { two_factor_required?: boolean; challenge?: string }>('/team/app-sessions', {
        login,
        password,
        device_name: deviceName,
        app: 'meu-dia',
      });
      if (data?.two_factor_required && data.challenge) {
        return { kind: 'twoFactor', challenge: data.challenge };
      }
      return { kind: 'ok', session: data };
    },
    verifyTwoFactor: (challenge: string, code: string) => client.post<Session>('/team/app-sessions/verify', { challenge, code }),
    logout: () => client.delete<{ revoked: boolean }>('/team/app-sessions/current'),
    me: () => client.get<Me>('/team/me'),
    team: () => client.get<Person[]>('/my-day/team'),
    day: (date?: string, personId?: number | null) => client.get<Day>('/my-day', { date, person_id: personId ?? undefined }),
    overdue: (personId?: number | null) => client.get<Overdue>('/my-day/overdue', { person_id: personId ?? undefined }),
    createTask: (task: NewTask) => client.post<{ id: number }>('/my-day/events', task),
    setStatus: (id: number, status: EventStatus) => client.post<{ id: number; status: EventStatus }>(`/my-day/events/${id}/status`, { status }),
    registerPush: (token: string, platform: string) => client.post<unknown>('/team/push-devices', { token, platform, app: 'meu-dia' }),
    forgetPush: (token: string) => client.delete<unknown>('/team/push-devices', { token, app: 'meu-dia' }),
  };
}

export type TeamApi = ReturnType<typeof teamApi>;
