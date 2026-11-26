import { ApiError, createClient } from '../src/api/client';
import { teamApi } from '../src/api/team';

type Call = { url: string; init: RequestInit };

function fake(status: number, body: unknown, calls: Call[]): typeof fetch {
  return (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    return { ok: status >= 200 && status < 300, status, text: async () => JSON.stringify(body) } as Response;
  }) as unknown as typeof fetch;
}

describe('API do Meu Dia', () => {
  it('manda o token Bearer e o dia pedido', async () => {
    const calls: Call[] = [];
    const api = teamApi(createClient({ baseUrl: 'https://erp.connect.lab/', getToken: () => 'abc', fetchImpl: fake(200, { data: { date: '2026-11-26' } }, calls) }));
    await api.day('2026-11-26', 7);
    expect(calls[0]!.url).toBe('https://erp.connect.lab/api/v1/my-day?date=2026-11-26&person_id=7');
    expect((calls[0]!.init.headers as Record<string, string>).Authorization).toBe('Bearer abc');
  });

  it('login com 2FA devolve o desafio', async () => {
    const calls: Call[] = [];
    const api = teamApi(createClient({ baseUrl: 'https://x', getToken: () => null, fetchImpl: fake(202, { data: { two_factor_required: true, challenge: 'c'.repeat(64) } }, calls) }));
    await expect(api.login('a@b.c', 'senha', 'iPhone')).resolves.toEqual({ kind: 'twoFactor', challenge: 'c'.repeat(64) });
    expect(JSON.parse(String(calls[0]!.init.body))).toMatchObject({ login: 'a@b.c', app: 'meu-dia' });
  });

  it('nova tarefa para outra pessoa vai com assigned_to; erro 422 traz os campos', async () => {
    const calls: Call[] = [];
    const api = teamApi(createClient({ baseUrl: 'https://x', getToken: () => 't', fetchImpl: fake(422, { error: { code: 'validation', message: 'Confira', fields: { title: 'Curto' } } }, calls) }));
    const err = await api.createTask({ title: 'ab', date: '2026-11-27', start: '09:00', type: 'task', assigned_to: 5 }).catch((e) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect(err.fields).toEqual({ title: 'Curto' });
    expect(JSON.parse(String(calls[0]!.init.body)).assigned_to).toBe(5);
  });

  it('401 avisa para voltar ao login', async () => {
    let out = false;
    const api = teamApi(createClient({ baseUrl: 'https://x', getToken: () => 't', onUnauthenticated: () => (out = true), fetchImpl: fake(401, { error: { code: 'unauthenticated', message: 'Sessão terminou' } }, []) }));
    await expect(api.me()).rejects.toBeInstanceOf(ApiError);
    expect(out).toBe(true);
  });
});
