/**
 * Cliente da API REST do Connect ERP (/api/v1) para o app do morador.
 * Autentica pelo token Bearer da sessão do aparelho (ADR 0303); não usa cookie nem CSRF.
 * Respostas: {"data": ...}; erros: {"error": {"code", "message", "fields"?}}.
 */

export type FieldErrors = Record<string, string>;

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields: FieldErrors;

  constructor(status: number, code: string, message: string, fields: FieldErrors = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields;
  }

  /** Sessão do aparelho expirada ou revogada: o app volta para o login. */
  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  /** Função desligada pelo condomínio na matriz do portal. */
  get isFeatureDisabled(): boolean {
    return this.status === 403 && this.code === 'feature_disabled';
  }
}

export type Query = Record<string, string | number | boolean | null | undefined>;

export type ClientOptions = {
  baseUrl: string;
  getToken: () => string | null;
  getResidentId?: () => number | null;
  onUnauthenticated?: () => void;
  fetchImpl?: typeof fetch;
};

export type UploadFile = { uri: string; name: string; type: string };

const NETWORK_MESSAGE = 'Sem conexão com o servidor. Confira a internet e tente de novo.';

export function buildUrl(baseUrl: string, path: string, query: Query = {}, residentId: number | null = null): string {
  const base = baseUrl.replace(/\/+$/, '');
  const params: string[] = [];
  const all: Query = { ...query };
  if (residentId !== null && all.resident_id === undefined) {
    all.resident_id = residentId;
  }
  for (const [key, value] of Object.entries(all)) {
    if (value === null || value === undefined || value === '') {
      continue;
    }
    params.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
  }
  return `${base}/api/v1${path}${params.length ? `?${params.join('&')}` : ''}`;
}

export function createClient(options: ClientOptions) {
  const fetchImpl = options.fetchImpl ?? fetch;

  function headers(extra: Record<string, string> = {}): Record<string, string> {
    const h: Record<string, string> = { Accept: 'application/json', ...extra };
    const token = options.getToken();
    if (token) {
      h.Authorization = `Bearer ${token}`;
    }
    return h;
  }

  async function parse<T>(response: Response): Promise<T> {
    let body: unknown = null;
    const text = await response.text();
    if (text !== '') {
      try {
        body = JSON.parse(text);
      } catch {
        body = null;
      }
    }
    if (!response.ok) {
      const err = (body as { error?: { code?: string; message?: string; fields?: FieldErrors } } | null)?.error;
      const apiError = new ApiError(
        response.status,
        err?.code ?? `http_${response.status}`,
        err?.message ?? 'Não foi possível concluir. Tente de novo em instantes.',
        err?.fields ?? {},
      );
      if (apiError.isUnauthenticated) {
        options.onUnauthenticated?.();
      }
      throw apiError;
    }
    return ((body as { data?: T } | null)?.data ?? (null as T));
  }

  async function send<T>(method: string, path: string, query: Query, init: RequestInit): Promise<T> {
    const url = buildUrl(options.baseUrl, path, query, options.getResidentId?.() ?? null);
    let response: Response;
    try {
      response = await fetchImpl(url, { ...init, method });
    } catch {
      throw new ApiError(0, 'network', NETWORK_MESSAGE);
    }
    return parse<T>(response);
  }

  return {
    baseUrl: options.baseUrl,
    get<T>(path: string, query: Query = {}): Promise<T> {
      return send<T>('GET', path, query, { headers: headers() });
    },
    post<T>(path: string, body: unknown = {}, query: Query = {}): Promise<T> {
      return send<T>('POST', path, query, {
        headers: headers({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(body),
      });
    },
    delete<T>(path: string, query: Query = {}): Promise<T> {
      return send<T>('DELETE', path, query, { headers: headers() });
    },
    /** multipart/form-data (fotos da ocorrência). O fetch do React Native aceita {uri, name, type}. */
    upload<T>(path: string, fields: Record<string, string>, files: { field: string; file: UploadFile }[]): Promise<T> {
      const form = new FormData();
      for (const [key, value] of Object.entries(fields)) {
        form.append(key, value);
      }
      for (const { field, file } of files) {
        form.append(field, file as unknown as Blob);
      }
      return send<T>('POST', path, {}, { headers: headers(), body: form });
    },
    /** Endereço com resident_id, para baixar arquivo com o cabeçalho de autenticação. */
    url(path: string, query: Query = {}): string {
      return buildUrl(options.baseUrl, path, query, options.getResidentId?.() ?? null);
    },
    authHeaders(): Record<string, string> {
      return headers();
    },
  };
}

export type ApiClient = ReturnType<typeof createClient>;
