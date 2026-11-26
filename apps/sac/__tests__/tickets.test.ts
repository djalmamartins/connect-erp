import type { Ticket } from '../src/api/sac';
import { authorLabel, badgeCount, context, dueText, pushRoute, sortQueue, ticketKind, when } from '../src/lib/tickets';

function ticket(over: Partial<Ticket>): Ticket {
  return {
    id: 1, protocol: 'SAC202611260001', subject: 'Lâmpada', message: '', origin: 'residents', category: null, priority: 'medium',
    status: 'open', condominium: { id: 1, name: 'Lafayette' }, unit: { id: 2, label: 'Apto 101' },
    requester: { user_id: 9, name: 'Ana', email: null, phone: null }, assigned_to: null, due_at: '2026-11-27 18:00:00',
    created_at: '2026-11-26 09:00:00', updated_at: '2026-11-26 09:00:00', team: null, occurrence_id: null, late: false, ...over,
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
