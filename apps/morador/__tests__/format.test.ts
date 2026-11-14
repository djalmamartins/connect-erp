import { date, greeting, initials, longToday, money, parseDate, parseTime, periodLabel, statusLabel, statusTone, today } from '../src/lib/format';

describe('format', () => {
  it('formata centavos em reais', () => {
    expect(money(0)).toBe('R$ 0,00');
    expect(money(123456789)).toBe('R$ 1.234.567,89');
    expect(money(-505)).toBe('-R$ 5,05');
    expect(money(null)).toBe('—');
    expect(money(Number.NaN)).toBe('—');
  });

  it('formata datas e horas', () => {
    expect(date('2026-11-05')).toBe('05/11/2026');
    expect(date('2026-11-05 14:30:00')).toBe('05/11/2026 às 14:30');
    expect(date(null)).toBe('—');
    expect(today(new Date(2026, 0, 9))).toBe('2026-01-09');
  });

  it('lê datas digitadas e recusa datas que não existem', () => {
    expect(parseDate('25/12/2026')).toBe('2026-12-25');
    expect(parseDate(' 2026-02-28 ')).toBe('2026-02-28');
    expect(parseDate('30/02/2026')).toBeNull();
    expect(parseDate('2026-13-01')).toBeNull();
    expect(parseDate('amanhã')).toBeNull();
  });

  it('lê horas', () => {
    expect(parseTime('9:05')).toBe('09:05');
    expect(parseTime('23:59')).toBe('23:59');
    expect(parseTime('24:00')).toBeNull();
    expect(parseTime('12:60')).toBeNull();
  });

  it('traduz situações e tons como os cadastros do ERP', () => {
    expect(statusLabel('situation', 'overdue')).toBe('Vencido');
    expect(statusLabel('delivery', 'active')).toBe('Aguardando retirada');
    expect(statusLabel('reservation', 'active')).toBe('Aguardando aprovação');
    expect(statusLabel('occurrence', 'em_analise')).toBe('Em analise');
    expect(statusTone('situation', 'paid')).toBe('success');
    expect(statusTone('situation', 'overdue')).toBe('danger');
    expect(statusTone('visit', 'qualquer')).toBe('info');
  });

  it('nomeia o período da prestação de contas', () => {
    expect(periodLabel('2026-03')).toBe('Março de 2026');
    expect(periodLabel('2026', 'annual')).toBe('Ano de 2026');
    expect(periodLabel('2026-12', 'annual')).toBe('Ano de 2026');
  });
  it('monta o topo do Início', () => {
    expect(longToday(new Date(2026, 10, 14))).toBe('sábado, 14 de novembro');
    expect(greeting(new Date(2026, 10, 14, 8))).toBe('Bom dia');
    expect(greeting(new Date(2026, 10, 14, 13))).toBe('Boa tarde');
    expect(greeting(new Date(2026, 10, 14, 21))).toBe('Boa noite');
    expect(initials('Ana Paula Souza')).toBe('AS');
    expect(initials('  ana ')).toBe('A');
    expect(initials('')).toBe('?');
  });
});
