import { dayState, monthGrid, shiftMonth } from '../src/lib/calendar';

describe('calendário de reservas', () => {
  it('monta o mês começando no domingo', () => {
    const weeks = monthGrid('2026-11'); // 1/11/2026 é domingo
    expect(weeks[0][0]).toBe('2026-11-01');
    expect(weeks.flat().filter(Boolean)).toHaveLength(30);
    expect(weeks.every((w) => w.length === 7)).toBe(true);
    expect(monthGrid('2026-12')[0].slice(0, 3)).toEqual([null, null, '2026-12-01']); // terça
  });

  it('anda entre meses e anos', () => {
    expect(shiftMonth('2026-12', 1)).toBe('2027-01');
    expect(shiftMonth('2026-01', -1)).toBe('2025-12');
  });

  it('marca passado, fora do prazo, ocupado e parcial', () => {
    const area = { min_advance_days: 2, max_advance_days: 30 };
    const busy = [
      { date: '2026-11-20', starts_time: '10:00', ends_time: '14:00' },
      { date: '2026-11-21', starts_time: null, ends_time: null },
    ];
    expect(dayState('2026-11-13', '2026-11-14', area, busy)).toBe('past');
    expect(dayState('2026-11-15', '2026-11-14', area, busy)).toBe('outside');
    expect(dayState('2026-12-20', '2026-11-14', area, busy)).toBe('outside');
    expect(dayState('2026-11-20', '2026-11-14', area, busy)).toBe('partial');
    expect(dayState('2026-11-21', '2026-11-14', area, busy)).toBe('full');
    expect(dayState('2026-11-22', '2026-11-14', { min_advance_days: null, max_advance_days: null }, busy)).toBe('free');
  });
});
