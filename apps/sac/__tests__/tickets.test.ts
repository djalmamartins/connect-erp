import type { Ticket } from '../src/api/sac';
import { ago, authorLabel, badgeCount, channelOf, context, dueText, initials, newTicketProblem, placeLine, pushRoute, queueCondominium, sortQueue, ticketKind, when } from '../src/lib/tickets';

function ticket(over: Partial<Ticket>): Ticket {
  return {
    id: 1, protocol: 'SAC202611260001', subject: 'Lâmpada', message: '', origin: 'residents', category: null, priority: 'medium',
    status: 'open', condominium: { id: 1, name: 'Lafayette' }, unit: { id: 2, label: 'Apto 101' },
    requester: { user_id: 9, name: 'Ana', email: null, phone: null }, assigned_to: null, due_at: '2026-11-27 18:00:00',
    created_at: '2026-11-26 09:00:00', updated_at: '2026-11-26 09:00:00', team: null, tags: null, occurrence_id: null, late: false, ...over,
  };
}

const now = new Date(2026, 10, 26, 15, 0);

test('quando: hoje, ontem, amanhã e data', () => {
  expect(when('2026-11-26 14:05:00', now)).toBe('hoje 14:05');
  expect(when('2026-11-25 09:10:00', now)).toBe('ontem 09:10');
  expect(when('2026-11-27 18:00:00', now)).toBe('amanhã 18:00');
  expect(when('2026-11-20 08:00:00', now)).toBe('20/11 08:00');
});

test('prazo em palavras e atrasado', () => {
  expect(dueText(ticket({}), now)).toBe('Vence amanhã 18:00');
  expect(dueText(ticket({ late: true, due_at: '2026-11-25 17:00:00' }), now)).toBe('Atrasado desde ontem 17:00');
  expect(dueText(ticket({ status: 'resolved' }), now)).toBe('Resolvido');
});

test('fila: atrasados primeiro, finalizados por último, depois urgência e prazo', () => {
  const list = sortQueue([
    ticket({ id: 1, priority: 'low' }),
    ticket({ id: 2, priority: 'urgent' }),
    ticket({ id: 3, late: true, priority: 'low' }),
    ticket({ id: 4, status: 'resolved', priority: 'urgent' }),
    ticket({ id: 5, priority: 'urgent', due_at: '2026-11-26 16:00:00' }),
  ]);
  expect(list.map((t) => t.id)).toEqual([3, 5, 2, 1, 4]);
});

test('tipo, contexto, autor, push e selo', () => {
  expect(ticketKind(ticket({}))).toBe('chamado');
  expect(ticketKind(ticket({ protocol: 'OCO202611260001', occurrence_id: null }))).toBe('ocorrencia');
  expect(ticketKind(ticket({ occurrence_id: 3 }))).toBe('ocorrencia');
  expect(context(ticket({}))).toBe('Ana · Lafayette · Apto 101');
  expect(context(ticket({ requester: { user_id: null, name: null, email: null, phone: null }, unit: null }))).toBe('Lafayette');
  const m = { id: 1, author: 'Bia', from_team: true, message: 'x', internal: false, created_at: '', attachments: [] };
  expect(authorLabel(m)).toBe('Bia');
  expect(authorLabel({ ...m, internal: true })).toBe('Bia · nota interna');
  expect(authorLabel({ ...m, from_team: false })).toBe('Bia · quem pediu');
  expect(pushRoute({ path: '/chamados/12' })).toBe('/chamados/12');
  expect(pushRoute({ path: 'https://mal.example' })).toBeNull();
  expect(pushRoute(null)).toBeNull();
  expect(badgeCount({ mine_open: 3, unassigned: 2 })).toBe(5);
  expect(badgeCount(null)).toBe(0);
});

test('canal: tag do app vale primeiro, depois a origem', () => {
  expect(channelOf(ticket({ origin: 'talk' }))).toBe('whatsapp');
  expect(channelOf(ticket({ origin: 'residents' }))).toBe('app');
  expect(channelOf(ticket({ origin: 'helpdesk', tags: 'canal:telefone' }))).toBe('telefone');
  expect(channelOf(ticket({ origin: 'helpdesk', tags: 'urgente, canal:presencial' }))).toBe('presencial');
  expect(channelOf(ticket({ origin: 'erp', tags: 'canalizacao' }))).toBe('equipe');
  expect(channelOf(ticket({ origin: 'site' }))).toBe('site');
});

test('iniciais do responsável', () => {
  expect(initials('Ana Nunes')).toBe('AN');
  expect(initials('ana maria de souza')).toBe('AS');
  expect(initials('Ana')).toBe('AN');
  expect(initials(null)).toBe('');
});

test('há quanto tempo', () => {
  expect(ago('2026-11-26 14:56:00', now)).toBe('há 4 min');
  expect(ago('2026-11-26 13:00:00', now)).toBe('há 2 h');
  expect(ago('2026-11-25 22:00:00', now)).toBe('ontem');
  expect(ago('2026-11-20 08:00:00', now)).toBe('20/11');
  expect(ago('2026-11-26 15:00:00', now)).toBe('agora');
});

test('linha de onde e quem', () => {
  expect(placeLine(ticket({}))).toBe('Lafayette · Apto 101 · Ana');
  expect(placeLine(ticket({ unit: null, requester: { user_id: null, name: null, email: null, phone: null } }))).toBe('Lafayette');
});

test('novo chamado: confere antes de enviar', () => {
  const ok = { condominium_id: 1, requester_name: 'Carla', requester_phone: '(11) 98888-7777', requester_email: '', subject: 'Vazamento', message: 'Pingando desde cedo na vaga 22' };
  expect(newTicketProblem(ok)).toBeNull();
  expect(newTicketProblem({ ...ok, condominium_id: null })).toMatch(/condomínio/);
  expect(newTicketProblem({ ...ok, requester_phone: '' })).toMatch(/telefone/);
  expect(newTicketProblem({ ...ok, requester_phone: '', requester_email: 'carla@exemplo.com' })).toBeNull();
  expect(newTicketProblem({ ...ok, requester_email: 'carla@' })).toMatch(/e-mail/);
  expect(newTicketProblem({ ...ok, subject: 'Oi' })).toMatch(/assunto/);
  expect(newTicketProblem({ ...ok, message: 'curto' })).toMatch(/Descreva/);
});

import { presenceText } from '../src/lib/tickets';

test('chave do balão do site', () => {
  const base = { public: true, open: true, shown: true, hours: 'segunda a sexta, das 8h às 18h' };
  expect(presenceText(base).title).toBe('Você está online no balão do site');
  expect(presenceText({ ...base, public: false }).title).toBe('Você não aparece no balão do site');
  expect(presenceText({ ...base, open: false, shown: false }).detail).toContain('das 8h às 18h');
});

import { clock } from '../src/lib/useTimer';

test('cronômetro do tempo dedicado', () => {
  expect(clock(0)).toBe('0:00');
  expect(clock(42)).toBe('0:42');
  expect(clock(725)).toBe('12:05');
  expect(clock(3729)).toBe('1:02:09');
});

describe('queueCondominium (ADR 0670)', () => {
  const list = [{ id: 3 }, { id: 7 }];
  it('mantém o condomínio guardado que ainda está no escopo', () => {
    expect(queueCondominium('7', list)).toBe(7);
  });
  it('volta para todos quando não há filtro, o salvo saiu do escopo ou só há um condomínio', () => {
    expect(queueCondominium(null, list)).toBe(0);
    expect(queueCondominium('9', list)).toBe(0);
    expect(queueCondominium('abc', list)).toBe(0);
    expect(queueCondominium('3', [{ id: 3 }])).toBe(0);
  });
});
