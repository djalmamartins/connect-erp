import type { StatementLine } from '../src/api/resident';
import { nextBoleto, pendingLines } from '../src/lib/boleto';

jest.mock('expo-clipboard', () => ({ setStringAsync: jest.fn() }));
jest.mock('../src/lib/openFile', () => ({ openProtectedFile: jest.fn() }));

const charge = { id: 1, status: 'registered', digitable_line: '123', barcode: null, pix_copy_paste: null, pdf_available: true };
const line = (id: number, due: string, situation: string, withCharge = true) =>
  ({ entry_id: id, description: `c${id}`, competence: '2026-11', due_on: due, paid_on: null, amount_cents: 100, situation, charge: withCharge ? { ...charge, id } : null }) as unknown as StatementLine;

describe('segunda via no Início', () => {
  it('pega a conta em aberto mais antiga que tem boleto', () => {
    const lines = [line(1, '2026-12-20', 'open'), line(2, '2026-10-20', 'paid'), line(3, '2026-11-10', 'overdue', false), line(4, '2026-11-20', 'open')];
    expect(pendingLines(lines).map((l) => l.entry_id)).toEqual([3, 4, 1]);
    expect(nextBoleto(lines)?.entry_id).toBe(4);
  });

  it('não mostra nada sem boleto emitido', () => {
    expect(nextBoleto([line(1, '2026-11-20', 'open', false)])).toBeNull();
    expect(nextBoleto([])).toBeNull();
  });
});
