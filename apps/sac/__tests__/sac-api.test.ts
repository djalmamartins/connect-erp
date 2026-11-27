import { ApiError, createClient } from '../src/api/client';
import { sacApi } from '../src/api/sac';

type Call = { url: string; init: RequestInit };

function fake(responses: { status: number; body: unknown }[]) {
  const calls: Call[] = [];
  const fetchImpl = (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    const next = responses.shift() ?? { status: 200, body: { data: null } };
    return new Response(JSON.stringify(next.body), { status: next.status });
  }) as unknown as typeof fetch;
  return { calls, fetchImpl };
}

test('login da equipe: token ou desafio do 2FA', async () => {
  const { calls, fetchImpl } = fake([
    { status: 201, body: { data: { token: 'a'.repeat(64), expires_at: '2027-02-24', idle_days: 30 } } },
    { status: 202, body: { data: { two_factor_required: true, challenge: 'b'.repeat(64) } } },
  ]);
  const api = sacApi(createClient({ baseUrl: 'https://erp.x', getToken: () => null, fetchImpl }));
  await expect(api.login('a@b.c', 's', 'App Connect SAC (ios)')).resolves.toEqual({ kind: 'ok', session: { token: 'a'.repeat(64), expires_at: '2027-02-24', idle_days: 30 } });
  await expect(api.login('a@b.c', 's', 'x')).resolves.toEqual({ kind: 'twoFactor', challenge: 'b'.repeat(64) });
  expect(calls[0].url).toBe('https://erp.x/api/v1/team/app-sessions');
  expect(JSON.parse(String(calls[0].init.body))).toEqual({ login: 'a@b.c', password: 's', device_name: 'App Connect SAC (ios)' });
});

test('fila, Bearer e ações do atendimento', async () => {
  const { calls, fetchImpl } = fake([{ status: 200, body: { data: [] } }, { status: 201, body: { data: { message_id: 3 } } }, { status: 200, body: { data: {} } }]);
  const api = sacApi(createClient({ baseUrl: 'https://erp.x', getToken: () => 't0k', fetchImpl }));
  await api.tickets('unassigned', 'SAC2026');
  await api.answer(7, 'Oi', true, false);
  await api.forgetPush('ExponentPushToken[abc]');
  expect(calls[0].url).toBe('https://erp.x/api/v1/sac/tickets?view=unassigned&q=SAC2026');
  expect((calls[0].init.headers as Record<string, string>).Authorization).toBe('Bearer t0k');
  expect(calls[1].url).toBe('https://erp.x/api/v1/sac/tickets/7/answers');
  expect(JSON.parse(String(calls[1].init.body))).toEqual({ message: 'Oi', internal: true, resolve: false, time_spent: 0 });
  expect(calls[2].init.method).toBe('DELETE');
  expect(calls[2].url).toBe('https://erp.x/api/v1/team/push-devices?token=ExponentPushToken%5Babc%5D&app=sac');
});

test('Meu Dia sem acesso some (null); 401 chama a saída', async () => {
  let out = 0;
  const { fetchImpl } = fake([
    { status: 403, body: { error: { code: 'forbidden', message: 'Sem acesso' } } },
    { status: 401, body: { error: { code: 'unauthenticated', message: 'Sua sessão no app terminou.' } } },
  ]);
  const api = sacApi(createClient({ baseUrl: 'https://erp.x', getToken: () => 't', fetchImpl, onUnauthenticated: () => { out++; } }));
  await expect(api.myDay()).resolves.toBeNull();
  await expect(api.summary()).rejects.toBeInstanceOf(ApiError);
  expect(out).toBe(1);
});

import { formFields } from '../src/api/sac';
import { mimeFor, photoName, safeFileName } from '../src/lib/fileNames';

test('campos do Novo chamado em texto para o multipart', () => {
  expect(
    formFields({ condominium_id: 3, unit_id: null, requester_name: 'Carla', requester_phone: '11', requester_email: '', channel: 'telefone', subject: 'Vazamento', message: 'Pingando na vaga', priority: 'high', assign_me: true }),
  ).toEqual({ condominium_id: '3', unit_id: '', requester_name: 'Carla', requester_phone: '11', requester_email: '', channel: 'telefone', subject: 'Vazamento', message: 'Pingando na vaga', priority: 'high', assign_me: 'true' });
});

test('anexos: tipo pelo nome, nome seguro e nome da foto', () => {
  expect(mimeFor('foto-1.JPG')).toBe('image/jpeg');
  expect(mimeFor('ata.pdf')).toBe('application/pdf');
  expect(mimeFor('sem-extensao')).toBe('application/octet-stream');
  expect(safeFileName('../conta luz.pdf')).toBe('conta-luz.pdf');
  expect(photoName(0)).toBe('foto-1.jpg');
});
