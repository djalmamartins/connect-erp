import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import type { Day, TeamApi } from '../api/team';
import { storage } from '../auth/storage';
import { badgeCount, dayKey, eventReminders, morningSummary, nextMorning } from './day';

/**
 * Notificações do Meu Dia no celular.
 * - Locais (no próprio aparelho): 15 min antes de cada compromisso da semana e o resumo das 7h com as atrasadas.
 *   Funcionam sem internet e também no Expo Go. São refeitas a cada vez que o dia carrega.
 * - Push do servidor (ADR 0660/0659): quando alguém marca uma tarefa para você e o alerta das 7h de tarefas
 *   atrasadas. Precisa do projectId do EAS (app.json › extra.eas.projectId); sem ele, só as locais.
 * - O número no ícone do app é a quantidade de tarefas atrasadas.
 */
const CHANNEL = 'meu-dia';

export function configureDisplay(): void {
  if (Platform.OS === 'web') return;
  Notifications.setNotificationHandler({
    handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: true }),
  });
}

async function permission(): Promise<boolean> {
  if (Platform.OS === 'web') return false;
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync(CHANNEL, { name: 'Meu Dia', importance: Notifications.AndroidImportance.HIGH });
  }
  let { status } = await Notifications.getPermissionsAsync();
  if (status !== 'granted') status = (await Notifications.requestPermissionsAsync()).status;
  return status === 'granted';
}

function projectId(): string | null {
  const id = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId ?? Constants.easConfig?.projectId;
  return typeof id === 'string' && id !== '' ? id : null;
}

export async function registerPush(api: TeamApi): Promise<void> {
  const id = projectId();
  if (Platform.OS === 'web' || !id) return;
  try {
    if (!(await permission())) return;
    const token = (await Notifications.getExpoPushTokenAsync({ projectId: id })).data;
    await api.registerPush(token, Platform.OS);
    await storage.setPushToken(token);
  } catch {
    // Emulador sem push ou sem rede: tenta de novo na próxima abertura.
  }
}

export async function forgetPush(api: TeamApi): Promise<void> {
  const token = await storage.getPushToken();
  if (!token) return;
  try {
    await api.forgetPush(token);
  } catch {
    // Sem rede: o servidor apaga o token quando o Expo disser que o aparelho saiu.
  }
  await storage.setPushToken(null);
}

/** Apaga os lembretes locais e o número no ícone (ao sair ou desligar os lembretes). */
export async function clearReminders(): Promise<void> {
  if (Platform.OS === 'web') return;
  await Notifications.cancelAllScheduledNotificationsAsync().catch(() => undefined);
  await Notifications.setBadgeCountAsync(0).catch(() => undefined);
}

/**
 * Refaz os lembretes locais a partir do dia de hoje da própria pessoa. Só chame com o dia de HOJE e do próprio
 * usuário (não com o dia de outra pessoa da equipe).
 */
export async function syncReminders(day: Day, now: Date = new Date()): Promise<void> {
  if (Platform.OS === 'web') return;
  try {
    await Notifications.setBadgeCountAsync(badgeCount(day)).catch(() => undefined);
    if (!(await storage.getReminders()) || !(await permission())) return;
    await Notifications.cancelAllScheduledNotificationsAsync();
    for (const r of eventReminders(day.week_events ?? [], now).slice(0, 50)) {
      await Notifications.scheduleNotificationAsync({
        identifier: r.id,
        content: { title: r.title, body: r.body, data: r.data, sound: true },
        trigger: { type: Notifications.SchedulableTriggerInputTypes.DATE, date: r.at, channelId: CHANNEL },
      });
    }
    // Com o push do servidor ligado, o resumo das 7h vem de lá (ADR 0659), sempre atualizado; o local fica só sem push.
    if (await storage.getPushToken()) return;
    const morning = nextMorning(now);
    const summary = morningSummary(day, dayKey(morning));
    await Notifications.scheduleNotificationAsync({
      identifier: 'morning',
      content: { title: summary.title, body: summary.body, data: { app: 'meu-dia', kind: 'morning', date: dayKey(morning) }, sound: true },
      trigger: { type: Notifications.SchedulableTriggerInputTypes.DATE, date: morning, channelId: CHANNEL },
    });
  } catch {
    // Sem permissão ou limite do sistema: o dia continua aparecendo no app.
  }
}
