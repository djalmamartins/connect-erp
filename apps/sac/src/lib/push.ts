import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import type { SacApi } from '../api/sac';
import { storage } from '../auth/storage';

/**
 * Push do SAC (ADR 0660): chamado novo, mensagem de quem pediu e chamado passado para você.
 * Sem o projectId do EAS (app.json › extra.eas.projectId) ou na web não faz nada; a fila continua no app.
 */
export function pushProjectId(): string | null {
  const id = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId ?? Constants.easConfig?.projectId;
  return typeof id === 'string' && id !== '' ? id : null;
}

export function configurePushDisplay(): void {
  if (Platform.OS === 'web') return;
  Notifications.setNotificationHandler({
    handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: true }),
  });
}

export async function registerPush(api: SacApi): Promise<string | null> {
  const projectId = pushProjectId();
  if (Platform.OS === 'web' || !projectId) return null;
  try {
    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('sac', { name: 'Chamados do SAC', importance: Notifications.AndroidImportance.HIGH });
    }
    let { status } = await Notifications.getPermissionsAsync();
    if (status !== 'granted') status = (await Notifications.requestPermissionsAsync()).status;
    if (status !== 'granted') return null;
    const token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
    await api.registerPush(token, Platform.OS);
    await storage.setPushToken(token);
    return token;
  } catch {
    return null;
  }
}

export async function forgetPush(api: SacApi): Promise<void> {
  const token = await storage.getPushToken();
  if (!token) return;
  try {
    await api.forgetPush(token);
  } catch {
    // Sem rede: o servidor apaga quando o Expo disser que o aparelho saiu.
  }
  await storage.setPushToken(null);
}

/** Número no ícone do app (chamados meus + sem responsável). */
export async function setBadge(count: number): Promise<void> {
  if (Platform.OS === 'web') return;
  try {
    await Notifications.setBadgeCountAsync(Math.max(0, count));
  } catch {
    // Android sem suporte a selo: ignora.
  }
}
