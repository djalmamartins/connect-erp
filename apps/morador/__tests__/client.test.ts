import { ApiError, buildUrl, createClient } from '../src/api/client';
import { isFeatureOn, residentApi, type Me } from '../src/api/resident';

type Call = { url: string; init: RequestInit };

function fakeFetch(status: number, body: unknown, calls: Call[]) {
  return (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    return {
      ok: status >= 200 && status < 300,
      status,
      text: async () => (body === undefined ? '' : JSON.stringify(body)),
    } as Response;
  }) as unknown as typeof fetch;
}

/** Espera que a promessa falhe e devolve o ApiError. */
function failure(promise: Promise<unknown>): Promise<ApiError> {
  return promise.then(
    () => {
      throw new Error('Esperava erro.');
    },
    (e: unknown) => e as ApiError,
  );
}

describe('buildUrl', () => {
  it('monta /api/v1, tira barras e ignora vazios', () => {
    expect(buildUrl('https://app.connect.lab/', '/resident/me')).toBe('https://app.connect.lab/api/v1/resident/me');
    expect(buildUrl('https://x', '/resident/charges', { from: '2026-01-01', to: undefined, q: '' }, 7)).toBe(
      'https://x/api/v1/resident/charges?from=2026-01-01&resident_id=7',
    );
    expect(buildUrl('https://x', '/a', { resident_id: 3 }, 7)).toBe('https://x/api/v1/a?resident_id=3');
    expect(buildUrl('https://x', '/a', { q: 'a b&c' })).toBe('https://x/api/v1/a?q=a%20b%26c');
  });
});

describe('createClient', () => {
  it('manda o token Bearer e o vínculo escolhido, e devolve o data', async () => {
    const calls: Call[] = [];
    const client = createClient({ baseUrl: 'https://x', getToken: () => 'tok', getResidentId: () => 9, fetchImpl: fakeFetch(200, { data: { ok: 1 } }, calls) });
    await expect(client.get('/resident/summary')).resolves.toEqual({ ok: 1 });
    expect(calls[0].url).toBe('https://x/api/v1/resident/summary?resident_id=9');
    expect((calls[0].init.headers as Record<string, string>).Authorization).toBe('Bearer tok');
    expect(calls[0].init.method).toBe('GET');
  });

  it('não manda Authorization sem token e serializa o corpo em JSON', async () => {
    const calls: Call[] = [];
    const client = createClient({ baseUrl: 'https://x', getToken: () => null, fetchImpl: fakeFetch(201, { data: null }, calls) });
    await client.post('/resident/app-sessions', { login: 'a@b.c' });
    const headers = calls[0].init.headers as Record<string, string>;
    expect(headers.Authorization).toBeUndefined();
    expect(headers['Content-Type']).toBe('application/json');
    expect(calls[0].init.body).toBe('{"login":"a@b.c"}');
  });

  it('transforma o erro da API em ApiError com campos', async () => {
    const client = createClient({
      baseUrl: 'https://x',
      getToken: () => 't',
      fetchImpl: fakeFetch(422, { error: { code: 'validation', message: 'Confira os campos.', fields: { date: 'Data no passado.' } } }, []),
    });
    const error = await failure(client.post('/resident/reservations', {}));
    expect(error).toBeInstanceOf(ApiError);
    expect(error.status).toBe(422);
    expect(error.fields).toEqual({ date: 'Data no passado.' });
    expect(error.message).toBe('Confira os campos.');
  });

  it('reconhece função desligada e avisa quando a sessão cai', async () => {
    const onUnauthenticated = jest.fn();
    const disabled = createClient({ baseUrl: 'https://x', getToken: () => 't', fetchImpl: fakeFetch(403, { error: { code: 'feature_disabled', message: 'x' } }, []) });
    const e403 = await failure(disabled.get('/resident/deliveries'));
    expect(e403.isFeatureDisabled).toBe(true);

    const expired = createClient({ baseUrl: 'https://x', getToken: () => 't', onUnauthenticated, fetchImpl: fakeFetch(401, { error: { code: 'unauthenticated', message: 'x' } }, []) });
    await expect(expired.get('/resident/me')).rejects.toBeInstanceOf(ApiError);
    expect(onUnauthenticated).toHaveBeenCalledTimes(1);
  });

  it('erro de rede e resposta sem JSON viram mensagens claras', async () => {
    const offline = createClient({ baseUrl: 'https://x', getToken: () => 't', fetchImpl: (async () => { throw new TypeError('Network request failed'); }) as unknown as typeof fetch });
    const e = await failure(offline.get('/resident/me'));
    expect(e.code).toBe('network');
    const html = createClient({
      baseUrl: 'https://x',
      getToken: () => 't',
      fetchImpl: (async () => ({ ok: false, status: 502, text: async () => '<html>' })) as unknown as typeof fetch,
    });
    const e502 = await failure(html.get('/resident/me'));
    expect(e502.code).toBe('http_502');
  });
});

describe('residentApi', () => {
  it('login com 2FA devolve o desafio; sem 2FA devolve o token', async () => {
    const calls: Call[] = [];
    const twoFactor = residentApi(createClient({ baseUrl: 'https://x', getToken: () => null, fetchImpl: fakeFetch(202, { data: { two_factor_required: true, challenge: 'c1' } }, calls) }));
    await expect(twoFactor.login('a@b.c', 'senha', 'App')).resolves.toEqual({ kind: 'two_factor', challenge: 'c1' });
    expect(JSON.parse(String(calls[0].init.body))).toEqual({ login: 'a@b.c', password: 'senha', device_name: 'App' });

    const plain = residentApi(createClient({ baseUrl: 'https://x', getToken: () => null, fetchImpl: fakeFetch(201, { data: { token: 'abc', expires_at: '2027-01-01' } }, []) }));
    await expect(plain.login('a@b.c', 'senha', 'App')).resolves.toEqual({ kind: 'ok', session: { token: 'abc', expires_at: '2027-01-01' } });
  });

  it('cria ocorrência em JSON e cancela visita pela rota certa', async () => {
    const calls: Call[] = [];
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => 't', getResidentId: () => 2, fetchImpl: fakeFetch(201, { data: {} }, calls) }));
    await api.createOccurrence({ subject: 'Vazamento', description: 'Teto da garagem', priority: 'alta', occurred_on: '2026-11-14' });
    await api.cancelVisit(5);
    await api.createReservation({ common_area_id: 3, reserved_on: '2026-12-01', starts_time: '18:00' });
    expect(calls[0].url).toBe('https://x/api/v1/resident/occurrences?resident_id=2');
    expect(JSON.parse(String(calls[0].init.body)).priority).toBe('alta');
    expect(calls[1].url).toBe('https://x/api/v1/resident/visits/5/cancel?resident_id=2');
    expect(JSON.parse(String(calls[2].init.body))).toEqual({ common_area_id: 3, reserved_on: '2026-12-01', starts_time: '18:00' });
    expect(api.chargePdfUrl(8)).toBe('https://x/api/v1/resident/charges/8/pdf?resident_id=2');
  });

  it('lê as funções ligadas do vínculo atual', () => {
    const link = { resident_id: 1, name: 'Ana', relationship: 'owner', relationship_label: 'Proprietário', condominium_id: 1, condominium_name: 'C', unit_id: 1, unit_label: '101' };
    const me: Me = { user: { id: 1, name: 'Ana', email: 'a@b.c' }, current: { ...link, features: ['boletos'] }, links: [] };
    expect(isFeatureOn(me, 'boletos')).toBe(true);
    expect(isFeatureOn(me, 'deliveries')).toBe(false);
    expect(isFeatureOn(null, 'boletos')).toBe(false);
  });
});
