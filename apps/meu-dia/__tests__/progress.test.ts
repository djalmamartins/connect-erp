import type { DayEvent } from '../src/api/team';
import { addMinutes, dayProgress, eventTone, postponeOptions } from '../src/lib/day';

const ev = (id: number, start: string, status: DayEvent['status'] = 'scheduled', over: Partial<DayEvent> = {}): DayEvent => ({
  id,
  title: `Compromisso ${id}`,
  description: '',
  starts_at: `2026-11-27 ${start}:00`,
  ends_at: null,
  start,
  end: null,
  minutes: 30,
  type: 'task',
  type_label: 'Tarefa',
  status,
  condominium_id: null,
  condominium: null,
  ticket_id: null,
  protocol: null,
  ...over,
});

describe('progresso de hoje', () => {
  it('conta feitos sem os cancelados e acha o próximo pelo horário', () => {
    const events = [ev(1, '08:00', 'completed'), ev(2, '15:00'), ev(3, '10:00'), ev(4, '09:00', 'cancelled'), ev(5, '07:30')];
    const p = dayProgress({ events }, 2, '09:30');
    expect([p.done, p.total, p.percent, p.late]).toEqual([1, 4, 25, 2]);
    expect(p.next?.id).toBe(3);
    expect(dayProgress({ events }, 0, '16:00').next).toBeNull();
    expect(dayProgress({ events: [] }, 0, '09:00')).toMatchObject({ total: 0, percent: 0, next: null });
  });

  it('dá a cor do compromisso: feito, atrasado ou pelo tipo', () => {
    expect(eventTone(ev(1, '08:00', 'completed'), '2026-11-27')).toBe('done');
    expect(eventTone(ev(2, '08:00', 'scheduled', { starts_at: '2026-11-26 08:00:00' }), '2026-11-27')).toBe('late');
    expect(eventTone(ev(3, '08:00', 'scheduled', { type: 'meeting' }), '2026-11-27')).toBe('meeting');
    expect(eventTone(ev(4, '08:00', 'scheduled', { overdue: true }), '2026-11-27')).toBe('late');
  });
});

describe('adiar', () => {
  it('oferece +1 hora, amanhã e segunda no mesmo horário', () => {
    // 2026-11-27 é sexta.
    expect(postponeOptions(ev(1, '09:30'), '2026-11-27')).toEqual([
      { label: '+1 hora (10:30)', date: '2026-11-27', start: '10:30' },
      { label: 'Amanhã às 09:30', date: '2026-11-28', start: '09:30' },
      { label: 'Segunda às 09:30', date: '2026-11-30', start: '09:30' },
    ]);
  });

  it('atrasado vai para hoje; perto da meia-noite não tem +1 hora; domingo não repete a segunda', () => {
    const late = postponeOptions(ev(2, '08:00', 'scheduled', { starts_at: '2026-11-25 08:00:00' }), '2026-11-27');
    expect(late[0]).toEqual({ label: 'Hoje às 08:00', date: '2026-11-27', start: '08:00' });
    expect(postponeOptions(ev(3, '23:30'), '2026-11-27')[0]!.label).toBe('Amanhã às 23:30');
    const sunday = postponeOptions(ev(4, '10:00', 'scheduled', { starts_at: '2026-11-29 10:00:00' }), '2026-11-27');
    expect(sunday.map((o) => o.date)).toEqual(['2026-11-29', '2026-11-30']);
    expect(addMinutes('09:45', 30)).toBe('10:15');
    expect(addMinutes('23:30', 60)).toBeNull();
  });
});
