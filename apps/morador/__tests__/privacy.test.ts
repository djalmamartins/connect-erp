import type { Privacy } from '../src/api/resident';
import { brDate, needsTerms, requestOptions } from '../src/lib/privacy';

const base: Privacy = {
  terms_version: '2026-11-20',
  privacy_version: '2026-11-20',
  accepted: false,
  accepted_at: null,
  marketing: false,
  terms_url: 'https://www.exemplo.test/termos-de-uso',
  privacy_url: 'https://www.exemplo.test/politica-de-privacidade',
  requests: { acesso: 'Acesso', exclusao: 'Exclusão' },
  answer_days: 15,
};

describe('privacidade (ADR 0603)', () => {
  it('pede o aceite só quando o servidor diz que falta', () => {
    expect(needsTerms(base)).toBe(true);
    expect(needsTerms({ ...base, accepted: true })).toBe(false);
  });
  it('não prende o morador enquanto carrega ou sem a rota no servidor', () => {
    expect(needsTerms(null)).toBe(false);
    expect(needsTerms(undefined)).toBe(false);
  });
  it('lista os tipos de pedido na ordem do servidor', () => {
    expect(requestOptions(base)).toEqual([{ value: 'acesso', label: 'Acesso' }, { value: 'exclusao', label: 'Exclusão' }]);
    expect(requestOptions(null)).toEqual([]);
  });
  it('mostra a data do prazo no formato brasileiro', () => {
    expect(brDate('2026-12-05')).toBe('05/12/2026');
  });
});
