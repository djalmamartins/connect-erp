import type { Day, DayEvent } from '../src/api/team';
import { addDays, badgeCount, diffDays, eventReminders, lateLabel, morningSummary, nextMorning, overdueItems, relativeDay, shortDay, stripTone } from '../src/lib/day';

function event(over: Partial<DayEvent>): DayEvent {
  return {
    id: 1, title: 'Visita', description: '', starts_at: '2026-11-26 09:00:00', ends_at: null, start: '09:00', end: null, minutes: 30,
    type: 'meeting', type_label: 'Compromisso', status: 'scheduled', condominium_id: null, condominium: null, ticket_id: null, protocol: null, ...over,
  };
}

function day(over: Partial<Day> = {}): Day {
  return {
    date: '2026-11-26', today: '2026-11-26', person: { id: 1, name: 'Djalma' },
    summary: { commitments: 0, commitment_minutes: 0, done: 0, to_schedule: 0, waiting: 0, overdue: 0, week_visits: 0, week_condominiums: 0 },
    week: [], strip: [], events: [], week_events: [], day_visits: [], day_deadlines: [], to_schedule: [], waiting: [], visits: [], deadlines: [], ...over,
  };
}

describe('datas', () => {
  it('soma e compara dias, inclusive na virada do mês', () => {
    expect(addDays('2026-11-30', 1)).toBe('2026-12-01');
    expect(diffDays('2026-11-25', '2026-11-26')).toBe(1);
    expect(shortDay('2026-11-27')).toBe('sex, 27/11');
    expect(relativeDay('2026-11-25', '2026-11-26')).toBe('Ontem');
    expect(relativeDay('2026-11-27', '2026-11-26')).toBe('Amanhã');
    expect(lateLabel(1)).toBe('Atrasada desde ontem');
    expect(lateLabel(3)).toBe('Atrasada há 3 dias');
  });
});

describe('o que ficou para trás', () => {
  it('usa a lista do servidor quando ela vem, do mais antigo para o mais novo', () => {
    const d = day({ overdue: [
      { kind: 'ticket', id: 9, title: 'B', due_on: '2026-11-25', days_late: 1 },
      { kind: 'event', id: 3, title: 'A', due_on: '2026-11-20', days_late: 6 },
    ] });
    expect(overdueItems(d).map((i) => i.title)).toEqual(['A', 'B']);
    expect(badgeCount(d)).toBe(2);
  });

  it('sem a lista do servidor, monta com compromisso de ontem ainda marcado, chamado e vistoria vencidos', () => {
    const d = day({
      week_events: [
        event({ id: 1, title: 'Tarefa de ontem', starts_at: '2026-11-25 10:00:00' }),
        event({ id: 2, title: 'Feita ontem', starts_at: '2026-11-25 11:00:00', status: 'completed' }),
        event({ id: 3, title: 'Hoje', starts_at: '2026-11-26 11:00:00' }),
      ],
      to_schedule: [{ id: 7, protocol: 'SAC1', subject: 'Vazamento', priority: 'high', priority_label: 'Alta', tag: 'Importante', status: 'open', status_label: 'Aberto', category: '', due_at: '2026-11-24 18:00:00', due_on: '2026-11-24', overdue: true, due_today: false }],
      visits: [{ plan_id: 4, condominium_id: 1, condominium: 'Lafayette', address: '', template: 'Vistoria', frequency_label: 'Quinzenal', due_on: '2026-11-20', overdue: true }],
    });
    const items = overdueItems(d);
    expect(items.map((i) => [i.kind, i.title, i.days_late])).toEqual([
      ['visit', 'Vistoria · Lafayette', 6],
      ['ticket', 'Vazamento', 2],
      ['event', 'Tarefa de ontem', 1],
    ]);
  });

  it('pinta de vermelho só o dia passado com pendência', () => {
    const late = [{ kind: 'event' as const, id: 1, title: 'x', due_on: '2026-11-25', days_late: 1 }];
    expect(stripTone({ date: '2026-11-25', count: 1 }, '2026-11-26', late)).toBe('late');
    expect(stripTone({ date: '2026-11-24', count: 2 }, '2026-11-26', late)).toBe('busy');
    expect(stripTone({ date: '2026-11-27', count: 0 }, '2026-11-26', late)).toBe('free');
    expect(stripTone({ date: '2026-11-24', count: 2, overdue: 1 }, '2026-11-26', [])).toBe('late');
  });
});

describe('lembretes', () => {
  const now = new Date(2026, 10, 26, 8, 0, 0);

  it('15 minutos antes, só compromissos marcados que ainda vão acontecer', () => {
    const list = eventReminders([
      event({ id: 1, starts_at: '2026-11-26 09:00:00', start: '09:00', condominium: 'Lafayette' }),
      event({ id: 2, starts_at: '2026-11-26 07:30:00' }),
      event({ id: 3, starts_at: '2026-11-26 10:00:00', status: 'completed' }),
      event({ id: 1, starts_at: '2026-11-26 09:00:00' }),
    ], now);
    expect(list).toHaveLength(1);
    expect(list[0]!.at).toEqual(new Date(2026, 10, 26, 8, 45, 0));
    expect(list[0]!.body).toBe('Visita · Lafayette');
  });

  it('compromisso que começa em menos de 15 minutos avisa logo', () => {
    const [r] = eventReminders([event({ starts_at: '2026-11-26 08:10:00' })], now);
    expect(r!.at.getTime()).toBe(now.getTime() + 5000);
  });

  it('resumo da manhã: hoje às 7h se ainda não passou, senão amanhã', () => {
    expect(nextMorning(new Date(2026, 10, 26, 6, 0))).toEqual(new Date(2026, 10, 26, 7, 0));
    expect(nextMorning(now)).toEqual(new Date(2026, 10, 27, 7, 0));
  });

  it('o resumo de amanhã conta as tarefas de hoje que ainda não fecharam', () => {
    const d = day({
      overdue: [{ kind: 'ticket', id: 1, title: 'x', due_on: '2026-11-24', days_late: 2 }],
      week_events: [event({ id: 5, starts_at: '2026-11-26 15:00:00' }), event({ id: 6, starts_at: '2026-11-27 09:30:00', start: '09:30' })],
    });
    expect(morningSummary(d, '2026-11-27')).toEqual({ title: 'Bom dia! 2 tarefas atrasadas', body: '1 compromisso (o primeiro às 09:30).' });
    expect(morningSummary(day(), '2026-11-27').title).toBe('Bom dia! Seu dia no Connect');
  });
});
