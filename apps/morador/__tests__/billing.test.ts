import type { StatementLine } from '../src/api/resident';
import { historyWindow, mergeLines } from '../src/lib/billing';

describe('rolagem infinita dos boletos', () => {
  it('começa com 6 meses para trás e 3 para a frente', () => {
    expect(historyWindow('2026-11-14', null)).toEqual({ from: '2026-05-01', to: '2027-02-28' });
  });

  it('cada página busca os 6 meses antes da anterior, sem sobrepor', () => {
    expect(historyWindow('2026-11-14', '2026-05-01')).toEqual({ from: '2025-11-01', to: '2026-04-30' });
    expect(historyWindow('2026-11-14', '2025-11-01')).toEqual({ from: '2025-05-01', to: '2025-10-31' });
  });

  it('junta sem repetir e mantém a mais nova primeiro', () => {
    const l = (id: number, due: string) => ({ entry_id: id, due_on: due }) as StatementLine;
    expect(mergeLines([l(2, '2026-10-20'), l(1, '2026-09-20')], [l(1, '2026-09-20'), l(0, '2026-04-20')]).map((x) => x.entry_id)).toEqual([2, 1, 0]);
  });
});
