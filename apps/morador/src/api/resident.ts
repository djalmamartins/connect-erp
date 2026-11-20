/**
 * Rotas do morador (/api/v1/resident, ADR 0302, ResidentPortal) e da sessão do aparelho (ADR 0303).
 * Valores em centavos (amount_cents), datas AAAA-MM-DD, horas HH:MM. Todas as rotas do morador aceitam
 * ?resident_id= para escolher o vínculo; o cliente acrescenta o vínculo escolhido no app.
 */
import type { ApiClient, UploadFile } from './client';

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
  | 'assemblies'
  | 'household';

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

/** Aviso do sininho (ResidentFeed::notifications). path é o caminho da tela no app web. */
export type ResidentNotification = {
  kind: string;
  title: string;
  detail: string;
  at: string;
  path: string;
  tone: 'danger' | 'warning' | 'info' | 'success' | string;
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

export type AreaAvailability = { area_id: number; month: string; busy: { date: string; starts_time: string | null; ends_time: string | null }[] };

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

/** Chamado do Help Desk aberto pelo morador (ADR 0305). Ocorrência também vira chamado. */
export type TicketStatus = 'open' | 'in_progress' | 'waiting_customer' | 'resolved' | 'closed';
export type TicketKind = 'chamado' | 'ocorrencia';

export type Ticket = {
  id: number;
  number: string;
  subject: string;
  status: TicketStatus | string;
  status_label: string;
  priority: Priority | string;
  created_at: string;
  updated_at: string;
  last_message_at: string | null;
  occurrence_id: number | null;
  /** Desde a etapa 3; em chamados antigos pode faltar (usar ticketKind). */
  kind?: TicketKind;
};

export type TicketMessage = {
  id: number;
  author: 'morador' | 'equipe';
  author_name: string;
  body: string;
  created_at: string;
  attachments: unknown[];
};

export type TicketDetail = Ticket & { messages: TicketMessage[] };

export const TICKETS_PER_PAGE = 20;

/** Pessoas da unidade (etapa 4). Sem CPF, e-mail ou telefone na lista (LGPD). */
export type HouseholdRelationship = 'owner' | 'tenant' | 'resident' | 'financial';

export type HouseholdMember = {
  id: number;
  name: string;
  relationship: HouseholdRelationship | string;
  relationship_label: string;
  status: 'active' | 'blocked' | string;
  status_label?: string;
  starts_on: string | null;
  ends_on: string | null;
  is_me: boolean;
  can_block: boolean;
};

export type Household = { data: HouseholdMember[]; meta: { can_add: ('resident' | 'tenant')[] } };

export type NewHouseholdMember = {
  relationship: 'resident' | 'tenant';
  name: string;
  document?: string;
  email?: string;
  phone?: string;
  starts_on?: string;
};

/** Certidão negativa de débitos da unidade (etapa 5). Vale como foto do dia em que foi emitida. */
export type Certificate = {
  id: number;
  as_of: string;
  result: 'clear' | 'debts' | string;
  result_label: string;
  total_cents: number;
  verification_code: string;
  issued_at: string;
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

/** Verificação em duas etapas da conta (opcional para morador; obrigatória para quem também é da equipe). */
export type TwoFactorStatus = {
  enabled: boolean;
  configured: boolean;
  /** true quando a conta não pode desligar (conta de equipe que também é moradora). */
  required: boolean;
  recovery_remaining: number;
  setup?: { secret: string; uri: string };
};

/** Primeiro acesso (boas-vindas) e foto do perfil: valem para a conta, não para o vínculo. */
export type Onboarding = { completed: boolean; has_photo: boolean };

/** Privacidade da conta (LGPD, ADR 0604): aceite dos termos, novidades e pedidos do titular. */
export type Privacy = {
  terms_version: string;
  privacy_version: string;
  accepted: boolean;
  accepted_at: string | null;
  marketing: boolean;
  terms_url: string;
  privacy_url: string;
  requests: Record<string, string>;
  answer_days: number;
};
export type PrivacyRequestResult = { id: number; protocol: string; answer_until: string };

export const FORGOT_MESSAGE = 'Se a conta existir, enviamos um link para o e-mail cadastrado.';

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
    notifications: () => api.get<ResidentNotification[]>('/resident/notifications'),
    charges: (from?: string, to?: string) => api.get<ChargesResponse>('/resident/charges', { from, to }),
    chargePdfUrl: (id: number) => api.url(`/resident/charges/${id}/pdf`),
    accountability: () => api.get<AccountabilityLink[]>('/resident/accountability'),
    announcements: () => api.get<Announcement[]>('/resident/announcements'),
    commonAreas: () => api.get<CommonArea[]>('/resident/common-areas'),
    areaAvailability: (id: number, month: string) => api.get<AreaAvailability>(`/resident/common-areas/${id}/availability`, { month }),
    reservations: () => api.get<{ reservations: Reservation[]; waitlist: WaitlistEntry[] }>('/resident/reservations'),
    createReservation: (body: { common_area_id: number; reserved_on: string; starts_time?: string; ends_time?: string; notes?: string }) =>
      api.post<unknown>('/resident/reservations', body),
    cancelReservation: (id: number) => api.post<unknown>(`/resident/reservations/${id}/cancel`),
    joinWaitlist: (body: { common_area_id: number; desired_on: string; notes?: string }) => api.post<unknown>('/resident/waitlist', body),
    occurrences: () => api.get<Occurrence[]>('/resident/occurrences'),
    /** Primeira versão sem foto (a foto vem depois, pelo Disco Virtual com antivírus). */
    createOccurrence: (body: { subject: string; description: string; priority: Priority; occurred_on: string }) =>
      api.post<unknown>('/resident/occurrences', body),
    tickets: (page = 1, kind?: TicketKind) => api.get<Ticket[]>('/resident/tickets', { page, kind }),
    ticket: (id: number) => api.get<TicketDetail>(`/resident/tickets/${id}`),
    /** Ocorrência pede a data do fato; chamado usa a data de hoje. */
    openTicket: (body: { kind: TicketKind; subject: string; description: string; priority: Priority; occurred_on: string }) =>
      api.post<TicketDetail>('/resident/tickets', body),
    replyTicket: (id: number, body: string) => api.post<{ message_id: number; status: string }>(`/resident/tickets/${id}/messages`, { body }),
    household: async (): Promise<Household> => {
      const r = await api.getWithMeta<HouseholdMember[], Partial<Household['meta']>>('/resident/unit/residents');
      return { data: r.data ?? [], meta: { can_add: r.meta?.can_add ?? [] } };
    },
    addHouseholdMember: (body: NewHouseholdMember) => api.post<HouseholdMember>('/resident/unit/residents', body),
    blockTenant: (id: number) => api.post<HouseholdMember>(`/resident/unit/residents/${id}/block`),
    certificates: () => api.get<Certificate[]>('/resident/certificates'),
    issueCertificate: () => api.post<Certificate>('/resident/certificates'),
    certificatePdfUrl: (id: number) => api.url(`/resident/certificates/${id}/pdf`),
    deliveries: () => api.get<Delivery[]>('/resident/deliveries'),
    visits: () => api.get<Visit[]>('/resident/visits'),
    createVisit: (body: { visitor_name: string; visitor_document?: string; expected_on: string; expected_until?: string; notes?: string }) =>
      api.post<unknown>('/resident/visits', body),
    cancelVisit: (id: number) => api.post<unknown>(`/resident/visits/${id}/cancel`),
    documents: () => api.get<DocumentItem[]>('/resident/documents'),
    documentFileUrl: (id: number) => api.url(`/resident/documents/${id}/file`),
    assemblies: () => api.get<AssemblyItem[]>('/resident/assemblies'),
    assembly: (id: number) => api.get<AssemblyDetail>(`/resident/assemblies/${id}`),
    twoFactor: () => api.get<TwoFactorStatus>('/resident/2fa'),
    confirmTwoFactor: (code: string) => api.post<{ enabled: true; recovery_codes: string[] }>('/resident/2fa/confirm', { code }),
    disableTwoFactor: (code: string) => api.post<{ enabled: false }>('/resident/2fa/disable', { code }),
    newRecoveryCodes: (code: string) => api.post<{ enabled: true; recovery_codes: string[] }>('/resident/2fa/recovery', { code }),
    /** Pública: pede o link de nova senha por e-mail. A troca da senha continua no link (/forget/{token}). */
    async forgotPassword(login: string): Promise<string> {
      const data = await api.post<{ message?: string } | null>('/resident/password/forgot', { login });
      return data?.message ?? FORGOT_MESSAGE;
    },
    privacy: () => api.get<Privacy>('/resident/privacy'),
    acceptTerms: (marketing: boolean) => api.post<Privacy>('/resident/privacy/accept', { accept_terms: true, marketing }),
    setMarketing: (marketing: boolean) => api.post<Privacy>('/resident/privacy/marketing', { marketing }),
    privacyRequest: (type: string, details: string) => api.post<PrivacyRequestResult>('/resident/privacy/requests', { type, details }),
    onboarding: () => api.get<Onboarding>('/resident/onboarding'),
    completeOnboarding: () => api.post<null>('/resident/onboarding/complete'),
    uploadPhoto: (file: UploadFile) => api.upload<{ has_photo: boolean }>('/resident/profile/photo', {}, [{ field: 'photo', file }]),
    deletePhoto: () => api.delete<null>('/resident/profile/photo'),
    /** A foto só abre com o token, por isso vai com os cabeçalhos (o Image do React Native aceita). */
    photoSource: (version: number) => ({ uri: api.url('/resident/profile/photo', { v: version }), headers: api.authHeaders() }),
    petsVehicles: () => api.get<{ pets: Pet[]; vehicles: Vehicle[] }>('/resident/pets-vehicles'),
  };
}

export type ResidentApi = ReturnType<typeof residentApi>;
