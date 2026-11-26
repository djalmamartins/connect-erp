import { applyMask } from '../src/lib/masks';

describe('applyMask', () => {
  it('data: põe as barras sozinho e aceita colar', () => {
    expect(applyMask('date', '2')).toBe('2');
    expect(applyMask('date', '22')).toBe('22');
    expect(applyMask('date', '221')).toBe('22/1');
    expect(applyMask('date', '22112026')).toBe('22/11/2026');
    expect(applyMask('date', '22/11/2026999')).toBe('22/11/2026');
    // apagar a barra: "22/" nunca aparece, então apagar o "1" de "22/1" deixa "22"
    expect(applyMask('date', '22/')).toBe('22');
  });

  it('hora', () => {
    expect(applyMask('time', '930')).toBe('93:0');
    expect(applyMask('time', '0930')).toBe('09:30');
  });

  it('CPF, CNPJ, celular e CEP', () => {
    expect(applyMask('cpf', '12345678901')).toBe('123.456.789-01');
    expect(applyMask('cpfCnpj', '12345678901')).toBe('123.456.789-01');
    expect(applyMask('cpfCnpj', '12345678000199')).toBe('12.345.678/0001-99');
    expect(applyMask('phone', '11987654321')).toBe('(11) 98765-4321');
    expect(applyMask('phone', '1133334444')).toBe('(11) 3333-4444');
    expect(applyMask('phone', '119')).toBe('(11) 9');
    expect(applyMask('cep', '01310100')).toBe('01310-100');
  });

  it('valor em reais e placa', () => {
    expect(applyMask('money', '5')).toBe('0,05');
    expect(applyMask('money', '123456')).toBe('1.234,56');
    expect(applyMask('money', '')).toBe('');
    expect(applyMask('plate', 'abc-1d23')).toBe('ABC1D23');
  });
});
