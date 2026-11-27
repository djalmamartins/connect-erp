import type { InspectionItem } from '../src/api/team';
import { answered, cancelReasonError, visibleItems, blockers, bySection, checkinLabel, needsPhoto, noteError, photoPath } from '../src/lib/inspection';

const item = (id: number, over: Partial<InspectionItem> = {}): InspectionItem => ({
  id,
  position: id,
  section: 'Portaria',
  label: `Item ${id}`,
  requires_photo: false,
  result: 'pending',
  note: null,
  photos: [],
  ...over,
});

describe('vistoria no app', () => {
  it('lista o que falta para concluir, igual ao servidor', () => {
    const items = [
      item(1, { result: 'ok' }),
      item(2),
      item(3, { result: 'issue', requires_photo: true }),
      item(4, { result: 'not_applicable', requires_photo: true }),
      item(5, { result: 'ok', requires_photo: true, photos: [{ id: 1, url: '', created_at: '' }] }),
    ];
    expect(answered(items)).toBe(4);
    expect(blockers(items)).toEqual(['Responda: Item 2', 'Foto obrigatória: Item 3']);
    expect(needsPhoto(items[3])).toBe(false);
  });

  it('agrupa por seção na ordem dos itens', () => {
    const groups = bySection([item(2, { section: 'Garagem' }), item(1), item(3, { section: '' })]);
    expect(groups.map((g) => [g.section, g.items.map((i) => i.id)])).toEqual([
      ['Portaria', [1]],
      ['Garagem', [2]],
      ['Itens', [3]],
    ]);
  });

  it('pede descrição do problema e explica o check-in', () => {
    expect(noteError('issue', ' a ')).toBe('Descreva o problema encontrado.');
    expect(noteError('issue', 'Lâmpada queimada')).toBeNull();
    expect(noteError('ok', '')).toBeNull();
    expect(checkinLabel({ checkin: { at: '', status: 'outside', distance_m: 312.4, note: 'portaria' } })).toBe('Check-in a 312 m do condomínio');
    expect(checkinLabel({ checkin: { at: '', status: 'inside', distance_m: 10, note: null } })).toBe('Check-in no condomínio');
  });
});

describe('foto da vistoria no app', () => {
  it('usa o endereço da API que vem do servidor, ou monta o mesmo', () => {
    expect(photoPath(12, { id: 5, api_path: '/inspections/12/photos/5' })).toBe('/inspections/12/photos/5');
    expect(photoPath(12, { id: 7 })).toBe('/inspections/12/photos/7');
  });
});

describe('cancelar a vistoria pelo app', () => {
  it('pede o motivo com as regras do servidor', () => {
    expect(cancelReasonError('  ')).toMatch(/Explique/);
    expect(cancelReasonError('fila')).toMatch(/Explique/);
    expect(cancelReasonError('Portaria fechada')).toBeNull();
    expect(cancelReasonError('x'.repeat(1001))).toMatch(/1000/);
  });
});

describe('vistoria concluída', () => {
  it('mostra só os problemas quando pedido', () => {
    const items = [{ result: 'ok' as const }, { result: 'issue' as const }, { result: 'not_applicable' as const }];
    expect(visibleItems(items, false)).toHaveLength(3);
    expect(visibleItems(items, true)).toEqual([{ result: 'issue' }]);
  });
});
