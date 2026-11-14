/**
 * Rotas do morador (/api/v1/resident, ADR 0302, ResidentPortal) e da sessão do aparelho (ADR 0303).
 * Valores em centavos (amount_cents), datas AAAA-MM-DD, horas HH:MM. Todas as rotas do morador aceitam
 * ?resident_id= para escolher o vínculo; o cliente acrescenta o vínculo escolhido no app.
 */
import type { ApiClient } from './client';

/** Funções da matriz do portal (ResidentPortalPermissions::FEATURES). */
export type Feature =
  | 'boletos'
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
  name: string;
  relationship: string;
  relationship_label: string;
  condominium_id: number;
  condominium_name: string;
  unit_id: number;
  unit_label: string;
  features: Feature[];
};

export type Me = {
  user: { id: number; name: string; email: string };
  current: ResidentLink;
  links: ResidentLink[];
};

export type Reservation = {
  id: number;
  area: string;
  reserved_on: string;
  starts_time: string | null;
  ends_time: string | null;
  fee_cents: number | null;
  status: string;
};

export type Announcement = { id: number; subject: string; body: string; published_at: string };

export type AssemblyItem = {
  id: number;
  kind: string;
  mode: string;
  title: string;
  location: string;
  first_call_at: string;
  second_call_at: string | null;
  status: string;
};

export type Summary = {
  features: Feature[];
  charges?: { open_count: number; open_cents: number; overdue_cents: number; next_due_on: string | null };
  deliveries_waiting?: number;
  next_reservations?: Reservation[];
  visits_today?: number;
  announcements?: Announcement[];
  assemblies?: AssemblyItem[];
};

export type Charge = {
  id: number;
  status: string;
  digitable_line: string | null;
  barcode: string | null;
  pix_copy_paste: string | null;
  pdf_available: boolean;
};

/** situation: open, overdue, paid, renegotiated (UnitStatement::situation). */
export type StatementLine = {
  entry_id: number;
  description: string;
  competence: string;
  due_on: string;
  paid_on: string | null;
  amount_cents: number;
  situation: string;
  charge: Charge | null;
};

export type ChargesResponse = {
  unit_label: string;
  from: string;
  to: string;
  as_of: string;
  totals: { open_cents: number; overdue_cents: number; paid_cents: number };
  lines: StatementLine[];
};

export type AccountabilityLink = {
  id: number;
  kind: 'monthly' | 'annual' | string;
  competence: string;
  version: number;
  generated_at: string;
  url: string;
};

export type CommonArea = {
  id: number;
  name: string;
  booking_mode: string;
  capacity: number | null;
  fee_cents: number | null;
  min_advance_days: number | null;
  max_advance_days: number | null;
  opening_time: string | null;
  closing_time: string | null;
  rules: string;
};

export type WaitlistEntry = { id: number; area: string; desired_on: string };

export type Priority = 'baixa' | 'media' | 'alta';

export type Occurrence = {
  id: number;
  subject: string;
  priority: Priority | string;
  occurred_on: string;
  description: string;
  status: string;
  has_ticket: boolean;
};

export type Delivery = {
  id: number;
  recipient: string;
  carrier: string;
  tracking_code: string | null;
  received_on: string;
  received_time: string | null;
  picked_up_on: string | null;
  picked_up_by: string | null;
  status: string;
};

export type Visit = {
  id: number;
  visitor_name: string;
  visitor_document: string | null;
  expected_on: string;
  expected_until: string | null;
  notes: string;
  status: string;
};

export type DocumentItem = {
  id: number;
  folder: string;
  folder_label: string;
  title: string;
  mime: string;
  size_bytes: number;
  uploaded_at: string;
};

export type AssemblyDetail = AssemblyItem & {
  minutes: string | null;
  items: { position: number; title: string; description: string; status: string; approved: boolean | null }[];
};

export type Pet = { name: string; species: string; size: string | null; breed: string | null; vaccinated: boolean };
export type Vehicle = { plate: string; kind: string; model: string | null; color: string | null; parking_spot: string | null };

/** Sessão do aparelho (ADR 0303). */
export type AppSession = { token: string; expires_at?: string | null };
export type LoginResult = { kind: 'ok'; session: AppSession } | { kind: 'two_factor'; challenge: string };

type LoginPayload = Partial<AppSession> & { two_factor_required?: boolean; challenge?: string };

export function isFeatureOn(me: Me | null, feature: Feature): boolean {
  return !!me?.current?.features?.includes(feature);
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
    logout: () => api.delete('/resident/app-sessions/current'),
    me: () => api.get<Me>('/resident/me'),
    summary: () => api.get<Summary>('/resident/summary'),
    charges: (from?: string, to?: string) => api.get<ChargesResponse>('/resident/charges', { from, to }),
    chargePdfUrl: (id: number) => api.url(`/resident/charges/${id}/pdf`),
    accountability: () => api.get<AccountabilityLink[]>('/resident/accountability'),
    announcements: () => api.get<Announcement[]>('/resident/announcements'),
    commonAreas: () => api.get<CommonArea[]>('/resident/common-areas'),
    reservations: () => api.get<{ reservations: Reservation[]; waitlist: WaitlistEntry[] }>('/resident/reservations'),
    createReservation: (body: { common_area_id: number; reserved_on: string; starts_time?: string; ends_time?: string; notes?: string }) =>
      api.post<unknown>('/resident/reservations', body),
    cancelReservation: (id: number) => api.post<unknown>(`/resident/reservations/${id}/cancel`),
    joinWaitlist: (body: { common_area_id: number; desired_on: string; notes?: string }) => api.post<unknown>('/resident/waitlist', body),
    occurrences: () => api.get<Occurrence[]>('/resident/occurrences'),
    /** Primeira versão sem foto (a foto vem depois, pelo Disco Virtual com antivírus). */
    createOccurrence: (body: { subject: string; description: string; priority: Priority; occurred_on: string }) =>
      api.post<unknown>('/resident/occurrences', body),
    deliveries: () => api.get<Delivery[]>('/resident/deliveries'),
    visits: () => api.get<Visit[]>('/resident/visits'),
    createVisit: (body: { visitor_name: string; visitor_document?: string; expected_on: string; expected_until?: string; notes?: string }) =>
      api.post<unknown>('/resident/visits', body),
    cancelVisit: (id: number) => api.post<unknown>(`/resident/visits/${id}/cancel`),
    documents: () => api.get<DocumentItem[]>('/resident/documents'),
    documentFileUrl: (id: number) => api.url(`/resident/documents/${id}/file`),
    assemblies: () => api.get<AssemblyItem[]>('/resident/assemblies'),
    assembly: (id: number) => api.get<AssemblyDetail>(`/resident/assemblies/${id}`),
    petsVehicles: () => api.get<{ pets: Pet[]; vehicles: Vehicle[] }>('/resident/pets-vehicles'),
  };
}

export type ResidentApi = ReturnType<typeof residentApi>;
