/**
 * Rotas do morador (/api/v1/resident, ADR 0302) e da sessão do aparelho (ADR 0303).
 * Valores em centavos (amount_cents), datas AAAA-MM-DD. Todas as rotas do morador aceitam
 * ?resident_id= para escolher o vínculo; o cliente acrescenta o vínculo escolhido no app.
 */
import type { ApiClient } from './client';

export type Feature =
  | 'charges'
  | 'accountability'
  | 'announcements'
  | 'reservations'
  | 'occurrences'
  | 'deliveries'
  | 'visitors'
  | 'documents'
  | 'assemblies';

export type ResidentLink = {
  resident_id: number;
  condominium_id: number;
  condominium_name: string;
  unit_id: number;
  unit_label: string;
  role?: string;
};

export type Me = {
  person: { id?: number; name: string; email?: string | null };
  links: ResidentLink[];
  current: ResidentLink | null;
  features: Feature[] | Record<string, boolean>;
};

export type Summary = {
  open_charges_count?: number;
  open_charges_cents?: number;
  overdue_charges_count?: number;
  next_due_date?: string | null;
  pending_deliveries?: number;
  upcoming_reservations?: number;
  open_occurrences?: number;
  unread_announcements?: number;
  [key: string]: unknown;
};

export type Charge = {
  id: number;
  status: string;
  digitable_line?: string | null;
  barcode?: string | null;
  pix_copy_paste?: string | null;
  pdf_available?: boolean;
};

export type StatementLine = {
  id: number;
  description: string;
  due_date: string | null;
  paid_at?: string | null;
  amount_cents: number;
  status: string;
  charge?: Charge | null;
};

export type ChargesResponse = {
  lines?: StatementLine[];
  items?: StatementLine[];
  balance_cents?: number;
  open_cents?: number;
};

export type AccountabilityLink = {
  id?: number;
  period: string;
  kind?: string;
  url: string;
  published_at?: string | null;
};

export type Announcement = {
  id: number;
  title: string;
  body?: string | null;
  published_at?: string | null;
  audience?: string | null;
};

export type CommonArea = {
  id: number;
  name: string;
  rules?: string | null;
  fee_cents?: number | null;
  booking_mode?: string | null;
  requires_approval?: boolean;
};

export type Reservation = {
  id: number;
  common_area_id: number;
  common_area_name?: string;
  date: string;
  start_time?: string | null;
  end_time?: string | null;
  status: string;
  fee_cents?: number | null;
};

export type Priority = 'baixa' | 'media' | 'alta';

export type Occurrence = {
  id: number;
  subject?: string;
  priority?: Priority;
  occurred_on?: string | null;
  description: string;
  status: string;
  created_at?: string | null;
  photos_count?: number;
};

export type Delivery = {
  id: number;
  description?: string | null;
  carrier?: string | null;
  received_at?: string | null;
  delivered_at?: string | null;
  status: string;
};

export type Visit = {
  id: number;
  visitor_name: string;
  date: string;
  start_time?: string | null;
  end_time?: string | null;
  kind?: string | null;
  status: string;
};

export type DocumentItem = {
  id: number;
  title: string;
  category?: string | null;
  created_at?: string | null;
  file_name?: string | null;
  mime_type?: string | null;
};

export type AssemblyItem = {
  id: number;
  title: string;
  date?: string | null;
  kind?: string | null;
  status: string;
};

export type AssemblyDetail = AssemblyItem & {
  location?: string | null;
  agenda?: { order?: number; title: string; result?: string | null }[];
  minutes_available?: boolean;
  [key: string]: unknown;
};

export type Pet = { id: number; name: string; species?: string | null; breed?: string | null };
export type Vehicle = { id: number; plate: string; model?: string | null; color?: string | null; kind?: string | null };

/** Sessão do aparelho (ADR 0303). */
export type AppSession = { token: string; expires_at?: string | null };
export type LoginResult =
  | { kind: 'ok'; session: AppSession }
  | { kind: 'two_factor'; challenge: string };

type LoginPayload = Partial<AppSession> & { two_factor_required?: boolean; challenge?: string };

export function isFeatureOn(me: Me | null, feature: Feature): boolean {
  if (!me) {
    return false;
  }
  if (Array.isArray(me.features)) {
    return me.features.includes(feature);
  }
  return me.features[feature] === true;
}

export function residentApi(api: ApiClient) {
  return {
    async login(login: string, password: string, deviceName: string): Promise<LoginResult> {
      const data = await api.post<LoginPayload>('/resident/app-sessions', { login, password, device_name: deviceName });
      if (data?.two_factor_required && data.challenge) {
        return { kind: 'two_factor', challenge: data.challenge };
      }
      return { kind: 'ok', session: { token: String(data?.token ?? ''), expires_at: data?.expires_at ?? null } };
    },
    async verifyTwoFactor(challenge: string, code: string): Promise<AppSession> {
      const data = await api.post<LoginPayload>('/resident/app-sessions/verify', { challenge, code });
      return { token: String(data?.token ?? ''), expires_at: data?.expires_at ?? null };
    },
    logout(): Promise<unknown> {
      return api.delete('/resident/app-sessions/current');
    },
    me: () => api.get<Me>('/resident/me'),
    summary: () => api.get<Summary>('/resident/summary'),
    charges: (from?: string, to?: string) => api.get<ChargesResponse | StatementLine[]>('/resident/charges', { from, to }),
    chargePdfUrl: (id: number) => api.url(`/resident/charges/${id}/pdf`),
    accountability: () => api.get<AccountabilityLink[]>('/resident/accountability'),
    announcements: () => api.get<Announcement[]>('/resident/announcements'),
    commonAreas: () => api.get<CommonArea[]>('/resident/common-areas'),
    reservations: () => api.get<Reservation[]>('/resident/reservations'),
    createReservation: (body: { common_area_id: number; date: string; start_time?: string; end_time?: string }) =>
      api.post<Reservation>('/resident/reservations', body),
    cancelReservation: (id: number) => api.post<Reservation>(`/resident/reservations/${id}/cancel`),
    joinWaitlist: (body: { common_area_id: number; date: string }) => api.post<unknown>('/resident/waitlist', body),
    occurrences: () => api.get<Occurrence[]>('/resident/occurrences'),
    /** Primeira versão sem foto (a foto vem depois, pelo Disco Virtual com antivírus). */
    createOccurrence: (body: { subject: string; description: string; priority: Priority; occurred_on: string }) =>
      api.post<Occurrence>('/resident/occurrences', body),
    deliveries: () => api.get<Delivery[]>('/resident/deliveries'),
    visits: () => api.get<Visit[]>('/resident/visits'),
    createVisit: (body: { visitor_name: string; document?: string; date: string; start_time?: string; end_time?: string }) =>
      api.post<Visit>('/resident/visits', body),
    cancelVisit: (id: number) => api.post<Visit>(`/resident/visits/${id}/cancel`),
    documents: () => api.get<DocumentItem[]>('/resident/documents'),
    documentFileUrl: (id: number) => api.url(`/resident/documents/${id}/file`),
    assemblies: () => api.get<AssemblyItem[]>('/resident/assemblies'),
    assembly: (id: number) => api.get<AssemblyDetail>(`/resident/assemblies/${id}`),
    petsVehicles: () => api.get<{ pets: Pet[]; vehicles: Vehicle[] }>('/resident/pets-vehicles'),
  };
}

export type ResidentApi = ReturnType<typeof residentApi>;

/** O extrato pode vir como lista ou como objeto com as linhas. */
export function statementLines(data: ChargesResponse | StatementLine[] | null): StatementLine[] {
  if (!data) {
    return [];
  }
  if (Array.isArray(data)) {
    return data;
  }
  return data.lines ?? data.items ?? [];
}
