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

  it('servidor que não responde no prazo vira erro de prazo, sem a tela presa', async () => {
    const hanging = (async (_url: string, init: RequestInit) =>
      new Promise<Response>((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => reject(new Error('aborted')));
      })) as unknown as typeof fetch;
    const slow = createClient({ baseUrl: 'https://x', getToken: () => 't', fetchImpl: hanging, timeoutMs: 20 });
    const e = await failure(slow.get('/resident/charges'));
    expect(e.code).toBe('timeout');
    expect(e.message).toContain('demorou');
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

  it('liga a verificação em duas etapas pelas rotas do perfil', async () => {
    const calls: Call[] = [];
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => 't', fetchImpl: fakeFetch(200, { data: { enabled: true, recovery_codes: ['a1'] } }, calls) }));
    const confirmed = await api.confirmTwoFactor('123456');
    expect(confirmed.recovery_codes).toEqual(['a1']);
    await api.disableTwoFactor('654321');
    expect(calls.map((c) => [c.url, c.init.method, c.init.body])).toEqual([
      ['https://x/api/v1/resident/2fa/confirm', 'POST', JSON.stringify({ code: '123456' })],
      ['https://x/api/v1/resident/2fa/disable', 'POST', JSON.stringify({ code: '654321' })],
    ]);
    const wrong = residentApi(createClient({ baseUrl: 'https://x', getToken: () => 't', fetchImpl: fakeFetch(422, { error: { code: 'invalid_code', message: 'Código inválido.' } }, []) }));
    const error = await failure(wrong.newRecoveryCodes('000000'));
    expect([error.status, error.code]).toEqual([422, 'invalid_code']);
  });

  it('lê as funções ligadas do vínculo atual', () => {
    const link = { resident_id: 1, name: 'Ana', relationship: 'owner', relationship_label: 'Proprietário', condominium_id: 1, condominium_name: 'C', unit_id: 1, unit_label: '101' };
    const me: Me = { user: { id: 1, name: 'Ana', email: 'a@b.c' }, current: { ...link, features: ['boletos'] }, links: [] };
    expect(isFeatureOn(me, 'boletos')).toBe(true);
    expect(isFeatureOn(me, 'deliveries')).toBe(false);
    expect(isFeatureOn(null, 'boletos')).toBe(false);
  });
});

describe('etapa 2: senha, primeiro acesso e foto', () => {
  it('pede o link de nova senha sem token e usa a mensagem do servidor', async () => {
    const calls: Call[] = [];
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => null, fetchImpl: fakeFetch(202, { data: { message: 'Enviado.' } }, calls) }));
    await expect(api.forgotPassword('ana@x.com')).resolves.toBe('Enviado.');
    expect(calls[0]!.url).toBe('https://x/api/v1/resident/password/forgot');
    expect(JSON.parse(String(calls[0]!.init.body))).toEqual({ login: 'ana@x.com' });
    expect((calls[0]!.init.headers as Record<string, string>).Authorization).toBeUndefined();
  });

  it('cai na mensagem genérica quando o corpo vem vazio', async () => {
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => null, fetchImpl: fakeFetch(202, undefined, []) }));
    await expect(api.forgotPassword('123.456.789-09')).resolves.toMatch(/Se a conta existir/);
  });

  it('devolve o 429 do limite de tentativas como ApiError', async () => {
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => null, fetchImpl: fakeFetch(429, { error: { code: 'too_many_requests', message: 'Aguarde.' } }, []) }));
    const e = await failure(api.forgotPassword('ana@x.com'));
    expect(e.status).toBe(429);
  });

  it('a foto vai com o token e o vínculo, e a versão evita o cache', () => {
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => 'tok', getResidentId: () => 9, fetchImpl: fakeFetch(200, {}, []) }));
    const src = api.photoSource(3);
    expect(src.uri).toBe('https://x/api/v1/resident/profile/photo?v=3&resident_id=9');
    expect(src.headers.Authorization).toBe('Bearer tok');
  });

  it('envia a foto como multipart no campo photo', async () => {
    const calls: Call[] = [];
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => 'tok', fetchImpl: fakeFetch(200, { data: { has_photo: true } }, calls) }));
    await expect(api.uploadPhoto({ uri: 'file:///a.jpg', name: 'a.jpg', type: 'image/jpeg' })).resolves.toEqual({ has_photo: true });
    expect(calls[0]!.init.method).toBe('POST');
    expect(calls[0]!.init.body).toBeInstanceOf(FormData);
  });
});

describe('etapa 4: moradores da unidade', () => {
  it('lê a lista com o meta.can_add', async () => {
    const calls: Call[] = [];
    const body = { data: [{ id: 1, name: 'Ana', relationship: 'owner', relationship_label: 'Proprietária', status: 'active', starts_on: null, ends_on: null, is_me: true, can_block: false }], meta: { can_add: ['resident', 'tenant'] } };
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => 't', getResidentId: () => 4, fetchImpl: fakeFetch(200, body, calls) }));
    const r = await api.household();
    expect(calls[0]!.url).toBe('https://x/api/v1/resident/unit/residents?resident_id=4');
    expect(r.data).toHaveLength(1);
    expect(r.meta.can_add).toEqual(['resident', 'tenant']);
  });

  it('sem meta, ninguém cadastra', async () => {
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => 't', fetchImpl: fakeFetch(200, { data: [] }, []) }));
    await expect(api.household()).resolves.toEqual({ data: [], meta: { can_add: [] } });
  });

  it('bloqueio fora da regra vira ApiError 403', async () => {
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => 't', fetchImpl: fakeFetch(403, { error: { code: 'not_allowed', message: 'Só o proprietário.' } }, []) }));
    const e = await failure(api.blockTenant(9));
    expect(e.status).toBe(403);
    expect(e.code).toBe('not_allowed');
  });
});

describe('etapa 5: CND', () => {
  it('emite com POST sem corpo e monta o PDF com o vínculo', async () => {
    const calls: Call[] = [];
    const item = { id: 3, as_of: '2026-11-15', result: 'clear', result_label: 'Nada consta', total_cents: 0, verification_code: 'ABCD-EFGH-JKMN-PQRS', issued_at: '2026-11-15 11:00:00' };
    const api = residentApi(createClient({ baseUrl: 'https://x', getToken: () => 't', getResidentId: () => 4, fetchImpl: fakeFetch(201, { data: item }, calls) }));
    await expect(api.issueCertificate()).resolves.toEqual(item);
    expect(calls[0]!.url).toBe('https://x/api/v1/resident/certificates?resident_id=4');
    expect(calls[0]!.init.method).toBe('POST');
    expect(api.certificatePdfUrl(3)).toBe('https://x/api/v1/resident/certificates/3/pdf?resident_id=4');
  });
});
