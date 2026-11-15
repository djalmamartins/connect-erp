import type { Ticket } from '../src/api/resident';
import { canReply, hasMore, mergeTickets, ticketKind, when } from '../src/lib/tickets';

const t = (id: number): Ticket => ({
  id,
  number: `C-${id}`,
  subject: 'x',
  status: 'open',
  status_label: 'Aberto',
  priority: 'media',
  created_at: '2026-11-15 10:00:00',
  updated_at: '2026-11-15 10:00:00',
  last_message_at: null,
  occurrence_id: null,
});

describe('chamados', () => {
  it('junta as páginas sem repetir', () => {
    expect(mergeTickets([t(3), t(2)], [t(2), t(1)]).map((x) => x.id)).toEqual([3, 2, 1]);
  });
  it('página cheia indica que pode haver mais', () => {
    expect(hasMore(Array.from({ length: 20 }, (_, i) => t(i)))).toBe(true);
    expect(hasMore([t(1)])).toBe(false);
  });
  it('só chamado fechado não aceita resposta', () => {
    expect(canReply('closed')).toBe(false);
    expect(canReply('resolved')).toBe(true);
    expect(canReply('waiting_customer')).toBe(true);
  });
  it('mostra hoje, ontem ou a data', () => {
    const now = new Date(2026, 10, 15, 12, 0);
    expect(when('2026-11-15 09:05:00', now)).toBe('hoje, 09:05');
    expect(when('2026-11-14 23:59:00', now)).toBe('ontem, 23:59');
    expect(when('2026-11-02 08:00:00', now)).toBe('02/11, 08:00');
    expect(when('2025-12-31 08:00:00', now)).toBe('31/12/2025, 08:00');
    expect(when(null, now)).toBe('');
  });
});

describe('tipo do registro', () => {
  it('usa o kind do servidor e, nos antigos, o occurrence_id', () => {
    expect(ticketKind({ kind: 'chamado', occurrence_id: 3 })).toBe('chamado');
    expect(ticketKind({ occurrence_id: 3 })).toBe('ocorrencia');
    expect(ticketKind({ occurrence_id: null })).toBe('chamado');
  });
});
