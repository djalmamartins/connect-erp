import { LOGIN_EYEBROW, loginCopy, loginStep } from '../src/lib/loginScreen';

describe('tela de entrada no estilo do ERP (ADR 0657)', () => {
  it('escolhe a etapa pelo estado da tela', () => {
    expect(loginStep(false, false, false)).toBe('login');
    expect(loginStep(false, false, true)).toBe('two_factor');
    expect(loginStep(true, false, false)).toBe('forgot');
    expect(loginStep(true, true, false)).toBe('sent');
  });

  it('toda etapa tem título em duas partes e três dicas de segurança', () => {
    for (const step of ['login', 'forgot', 'sent', 'two_factor'] as const) {
      const copy = loginCopy(step);
      expect(copy.hero).toHaveLength(2);
      expect(copy.tips).toHaveLength(3);
      copy.tips.forEach((tip) => expect(tip.text.length).toBeGreaterThan(10));
    }
  });

  it('usa os mesmos textos do cartão da web', () => {
    expect(LOGIN_EYEBROW).toBe('CONNECT MORADOR');
    expect(loginCopy('login').cardTitle).toBe('Área do morador');
    expect(loginCopy('forgot').cardTitle).toBe('Recuperar senha');
    expect(loginCopy('two_factor').cardTitle).toBe('Verificação em duas etapas');
  });
});
