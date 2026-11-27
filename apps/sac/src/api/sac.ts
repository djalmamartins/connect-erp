import { ApiError, type ApiClient, type UploadFile } from './client';

/**
 * Rotas que o app do SAC usa (docs/api/openapi.yaml, tags Equipe e SAC; ADR 0660).
 * Login: /team/app-sessions. Fila e atendimento: /sac/*. Meu Dia de hoje: /my-day.
 */
export type Session = { token: string; expires_at: string; idle_days: number };
export type LoginResult = { kind: 'ok'; session: Session } | { kind: 'twoFactor'; challenge: string };

export type Me = {
  id: number;
  name: string;
  first_name: string;
  email: string;
  role: { slug: string; name: string } | null;
  two_factor_enabled: boolean;
  can_see_team: boolean;
  can_attend_sac: boolean;
};

export type TicketStatus = 'open' | 'in_progress' | 'waiting_customer' | 'resolved' | 'closed';
export type TicketPriority = 'low' | 'medium' | 'high' | 'urgent';
export type View = 'mine' | 'unassigned' | 'overdue' | 'today' | 'all';

export type Ticket = {
  id: number;
  protocol: string;
  subject: string;
  message: string;
  origin: string;
  category: string | null;
  priority: TicketPriority;
  status: TicketStatus;
  condominium: { id: number; name: string } | null;
  unit: { id: number; label: string } | null;
  requester: { user_id: number | null; name: string | null; email: string | null; phone: string | null };
  assigned_to: { id: number; name: string } | null;
  due_at: string;
  created_at: string;
  updated_at: string;
  team: string | null;
  tags: string | null;
  occurrence_id: number | null;
  late: boolean;
};

export type Message = {
  id: number;
  author: string;
  from_team: boolean;
  message: string;
  internal: boolean;
  created_at: string;
  attachments: { name: string; url: string }[];
};

export type TicketEvent = { type: string; label: string; old: string | null; new: string | null; author: string; created_at: string };

export type TicketDetail = {
  ticket: Ticket;
  messages: Message[];
  /** Fotos e arquivos mandados na abertura do chamado (ADR 0663). */
  opening_attachments?: { name: string; url: string }[];
  facts: { first_response_at: string | null; work_seconds: number; replies: number; internal_notes: number };
  events: TicketEvent[];
  can_attend: boolean;
};

export type Summary = {
  mine_open: number;
  mine_overdue: number;
  unassigned: number;
  overdue: number;
  due_today: number;
  open_total: number;
  can_attend: boolean;
};

export type Option = { value: string; label: string };
export type Options = {
  statuses: Option[];
  priorities: Option[];
  teams: string[];
  agents: { id: number; name: string }[];
  templates: { id: number; title: string; body: string }[];
  condominiums?: { id: number; name: string }[];
  channels?: Option[];
};

export type Unit = { id: number; label: string };

/** Chamado que a equipe abre pelo app (POST /sac/tickets, ADR 0661). */
export type NewTicket = {
  condominium_id: number;
  unit_id: number | null;
  requester_name: string;
  requester_phone: string;
  requester_email: string;
  channel: 'telefone' | 'whatsapp' | 'presencial';
  subject: string;
  message: string;
  priority: TicketPriority;
  assign_me: boolean;
};

/** Só o resumo do Meu Dia de hoje (o app Meu Dia mostra o resto). */
export type MyDayToday = {
  date: string;
  summary: { commitments: number; done: number; to_schedule: number; waiting: number; overdue: number };
};

export type Triage = Partial<{ status: TicketStatus; priority: TicketPriority; assigned_to: number | null; team: string }>;

export function sacApi(client: ApiClient) {
  return {
    async login(login: string, password: string, deviceName: string): Promise<LoginResult> {
      const data = await client.post<Session | { two_factor_required: true; challenge: string }>('/team/app-sessions', { login, password, device_name: deviceName });
      if ('two_factor_required' in data && data.two_factor_required) {
        return { kind: 'twoFactor', challenge: data.challenge };
      }
      return { kind: 'ok', session: data as Session };
    },
    verifyTwoFactor: (challenge: string, code: string) => client.post<Session>('/team/app-sessions/verify', { challenge, code }),
    logout: () => client.delete<{ revoked: boolean }>('/team/app-sessions/current'),
    me: () => client.get<Me>('/team/me'),
    registerPush: (token: string, platform: string) => client.post('/team/push-devices', { token, app: 'sac', platform }),
    forgetPush: (token: string) => client.delete('/team/push-devices', { token, app: 'sac' }),

    summary: () => client.get<Summary>('/sac/summary'),
    options: () => client.get<Options>('/sac/options'),
    tickets: (view: View, q = '') => client.get<Ticket[]>('/sac/tickets', { view, q }),
    units: (condominiumId: number) => client.get<Unit[]>('/sac/units', { condominium_id: condominiumId }),
    open: (input: NewTicket, files: UploadFile[] = []) =>
      files.length > 0
        ? client.upload<{ id: number; protocol: string; due_at: string; ticket: Ticket }>('/sac/tickets', formFields(input), files.map((file) => ({ field: 'attachments[]', file })))
        : client.post<{ id: number; protocol: string; due_at: string; ticket: Ticket }>('/sac/tickets', input),
    ticket: (id: number) => client.get<TicketDetail>(`/sac/tickets/${id}`),
    take: (id: number) => client.post<{ changed: string[]; status: string; ticket: Ticket }>(`/sac/tickets/${id}/take`),
    triage: (id: number, input: Triage) => client.post<{ changed: string[]; status: string; ticket: Ticket }>(`/sac/tickets/${id}/triage`, input),
    /** Com fotos vai em multipart (attachments[]); sem fotos, JSON. */
    answer: (id: number, message: string, internal: boolean, resolve: boolean, files: UploadFile[] = []) =>
      files.length > 0
        ? client.upload<{ message_id: number; status: string; ticket: Ticket }>(`/sac/tickets/${id}/answers`, { message, internal: String(internal), resolve: String(resolve) }, files.map((file) => ({ field: 'attachments[]', file })))
        : client.post<{ message_id: number; status: string; ticket: Ticket }>(`/sac/tickets/${id}/answers`, { message, internal, resolve }),
    /** Cabeçalho com o token, para baixar anexo protegido. */
    authHeaders: () => client.authHeaders(),

    /** Meu Dia de hoje; sem acesso ao Meu Dia (403) volta null e o cartão some. */
    async myDay(): Promise<MyDayToday | null> {
      try {
        return await client.get<MyDayToday>('/my-day');
      } catch (e) {
        if (e instanceof ApiError && (e.status === 403 || e.status === 404)) return null;
        throw e;
      }
    },
  };
}

export type SacApi = ReturnType<typeof sacApi>;

/** Campos do Novo chamado como texto para o multipart (null vira vazio). */
export function formFields(input: NewTicket): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, value] of Object.entries(input)) {
    out[key] = value === null || value === undefined ? '' : String(value);
  }
  return out;
}
