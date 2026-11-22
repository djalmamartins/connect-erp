import { notificationRoute, pushRoute, stamp, unseenCount } from '../src/lib/notifications';

describe('notificationRoute', () => {
  it('leva o caminho da web para a tela do app', () => {
    expect(notificationRoute('/boletos/12')).toBe('/financeiro');
    expect(notificationRoute('/comunicados#c3')).toBe('/comunicados');
    expect(notificationRoute('/assembleias/7')).toBe('/assembleias/7');
    expect(notificationRoute('/encomendas')).toBe('/encomendas');
    expect(notificationRoute('/qualquer')).toBe('/avisos');
  });
});

describe('unseenCount', () => {
  const items = [{ at: '2026-11-15 08:00:00' }, { at: '2026-11-14 10:00:00' }, { at: '2026-11-30 19:30:00' }];
  it('conta tudo na primeira vez', () => expect(unseenCount(items, null, '2026-11-15 09:00:00')).toBe(3));
  it('data futura conta como agora e não fica nova para sempre', () => {
    expect(unseenCount(items, '2026-11-15 07:00:00', '2026-11-15 09:00:00')).toBe(2);
    expect(unseenCount(items, '2026-11-15 09:00:00', '2026-11-15 09:00:00')).toBe(0);
  });
  it('stamp no formato do servidor', () => expect(stamp(new Date(2026, 10, 5, 7, 3, 9))).toBe('2026-11-05 07:03:09'));
});

describe('chamados (etapa 3)', () => {
  it('ocorrência e chamado abrem a lista ou a conversa', () => {
    expect(notificationRoute('/ocorrencias')).toBe('/chamados');
    expect(notificationRoute('/chamados')).toBe('/chamados');
    expect(notificationRoute('/chamados/42')).toBe('/chamados/42');
    expect(notificationRoute('/chamados/abc')).toBe('/avisos');
  });
});

describe('andamento do chamado (ADR 0641)', () => {
  it('aviso do sininho com o caminho da web abre a conversa no app', () => {
    expect(notificationRoute('/ocorrencias/15')).toBe('/chamados/15');
  });
  it('toque no push abre o chamado; dado estranho não navega', () => {
    expect(pushRoute({ path: '/chamados/9' })).toBe('/chamados/9');
    expect(pushRoute({ path: '/ocorrencias/9' })).toBe('/chamados/9');
    expect(pushRoute({ path: 'https://fora.example' })).toBeNull();
    expect(pushRoute(null)).toBeNull();
    expect(pushRoute({})).toBeNull();
  });
});
