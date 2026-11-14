import { biometricLabel } from '../src/auth/biometrics';

// Valores do enum AuthenticationType do expo-local-authentication.
const FINGERPRINT = 1;
const FACE = 2;
const IRIS = 3;

describe('biometricLabel', () => {
  it('usa os nomes do iPhone', () => {
    expect(biometricLabel([FACE], 'ios')).toBe('Face ID');
    expect(biometricLabel([FINGERPRINT], 'ios')).toBe('Touch ID');
  });

  it('usa nomes simples no Android (Samsung e outros)', () => {
    expect(biometricLabel([FINGERPRINT], 'android')).toBe('digital');
    expect(biometricLabel([FACE], 'android')).toBe('reconhecimento facial');
    expect(biometricLabel([FINGERPRINT, FACE], 'android')).toBe('digital ou rosto');
    expect(biometricLabel([IRIS], 'android')).toBe('íris');
  });

  it('cai em "biometria" quando o aparelho não diz o tipo', () => {
    expect(biometricLabel([], 'android')).toBe('biometria');
    expect(biometricLabel([], 'ios')).toBe('biometria');
  });
});
