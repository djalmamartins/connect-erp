import { trackApprovals } from '../src/lib/approvals';

describe('trackApprovals', () => {
  it('avisa só a reserva que estava aguardando e foi confirmada', () => {
    const r = trackApprovals([{ id: 1, status: 'confirmed' }, { id: 2, status: 'active' }, { id: 3, status: 'confirmed' }], [1, 2], []);
    expect(r).toEqual({ pending: [2], unseen: [1] });
  });
  it('mantém o aviso até fechar e tira o que foi cancelado', () => {
    expect(trackApprovals([{ id: 1, status: 'confirmed' }, { id: 4, status: 'inactive' }], [], [1, 4])).toEqual({ pending: [], unseen: [1] });
  });
  it('reserva recusada não vira aviso de aprovada', () => {
    expect(trackApprovals([{ id: 5, status: 'inactive' }], [5], []).unseen).toEqual([]);
  });
});
