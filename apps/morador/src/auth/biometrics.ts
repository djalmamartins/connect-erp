import * as LocalAuthentication from 'expo-local-authentication';
import { Platform } from 'react-native';

/**
 * Entrada por biometria do próprio celular: Face ID ou Touch ID no iPhone, digital ou rosto no
 * Android (Samsung e os outros usam a mesma biometria do sistema). O app nunca vê a digital nem o
 * rosto: o sistema só responde se a pessoa é a dona do aparelho. A biometria destrava o token
 * que já está no cofre do aparelho; a senha não fica guardada.
 */
export type BiometricInfo = { available: boolean; label: string };

const { AuthenticationType } = LocalAuthentication;

/** Nome que o morador reconhece, conforme o que o aparelho tem. */
export function biometricLabel(types: number[], os: string): string {
  const face = types.includes(AuthenticationType.FACIAL_RECOGNITION);
  const finger = types.includes(AuthenticationType.FINGERPRINT);
  if (os === 'ios') {
    if (face) return 'Face ID';
    if (finger) return 'Touch ID';
    return 'biometria';
  }
  if (face && finger) return 'digital ou rosto';
  if (finger) return 'digital';
  if (face) return 'reconhecimento facial';
  if (types.includes(AuthenticationType.IRIS)) return 'íris';
  return 'biometria';
}

export async function biometricInfo(): Promise<BiometricInfo> {
  if (Platform.OS === 'web') {
    return { available: false, label: 'biometria' };
  }
  try {
    const [hardware, enrolled, types] = await Promise.all([
      LocalAuthentication.hasHardwareAsync(),
      LocalAuthentication.isEnrolledAsync(),
      LocalAuthentication.supportedAuthenticationTypesAsync(),
    ]);
    return { available: hardware && enrolled, label: biometricLabel(types, Platform.OS) };
  } catch {
    return { available: false, label: 'biometria' };
  }
}

/** Pede a biometria; se ela falhar várias vezes, o sistema oferece o código do celular. */
export async function askBiometric(label: string): Promise<boolean> {
  try {
    const result = await LocalAuthentication.authenticateAsync({
      promptMessage: `Entrar no Connect com ${label}`,
      cancelLabel: 'Cancelar',
      fallbackLabel: 'Usar o código do celular',
    });
    return result.success;
  } catch {
    return false;
  }
}
