import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import type { ResidentApi } from '../api/resident';
import { tokenStorage } from '../auth/storage';

/**
 * Push dos chamados no celular (ADR 0641). Depois do login, pede a permissão de notificação e manda o token do
 * Expo para o servidor; ao sair, tira. Sem o projectId do EAS (app.json › extra.eas.projectId) ou na web, não faz
 * nada: o morador continua vendo o andamento no sininho.
 */
export function pushProjectId(): string | null {
  const id = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId ?? Constants.easConfig?.projectId;
  return typeof id === 'string' && id !== '' ? id : null;
}

/** Aviso aparece mesmo com o app aberto. */
export function configurePushDisplay(): void {
  if (Platform.OS === 'web') return;
  Notifications.setNotificationHandler({
    handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
  });
}

export async function registerPush(api: ResidentApi): Promise<string | null> {
  const projectId = pushProjectId();
  if (Platform.OS === 'web' || !projectId) return null;
  try {
    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('chamados', { name: 'Chamados', importance: Notifications.AndroidImportance.DEFAULT });
    }
    let { status } = await Notifications.getPermissionsAsync();
    if (status !== 'granted') {
      status = (await Notifications.requestPermissionsAsync()).status;
    }
    if (status !== 'granted') return null;
    const token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
    await api.registerPush(token, Platform.OS);
    await tokenStorage.setPushToken(token);
    return token;
  } catch {
    // Emulador sem serviço de push ou sem rede: tenta de novo no próximo login.
    return null;
  }
}

export async function forgetPush(api: ResidentApi): Promise<void> {
  const token = await tokenStorage.getPushToken();
  if (!token) return;
  try {
    await api.forgetPush(token);
  } catch {
    // Sem rede: o servidor apaga o token quando o Expo disser que o aparelho saiu.
  }
  await tokenStorage.setPushToken(null);
}
