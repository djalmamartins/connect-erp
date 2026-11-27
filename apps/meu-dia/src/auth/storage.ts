import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * Token da sessão do aparelho no Keychain (iOS) / Keystore (Android). Na web (só desenvolvimento) fica na memória.
 * A senha nunca é guardada; só o último e-mail usado, para já vir preenchido.
 */
const TOKEN_KEY = 'connect.meudia.token';
const LOGIN_KEY = 'connect.meudia.last_login';
const PUSH_KEY = 'connect.meudia.push_token';
const REMINDERS_KEY = 'connect.meudia.reminders';
const ARRIVAL_KEY = 'connect.meudia.arrival';
const memory = new Map<string, string>();

async function read(key: string): Promise<string | null> {
  if (Platform.OS === 'web') return memory.get(key) ?? null;
  return SecureStore.getItemAsync(key);
}

async function write(key: string, value: string | null): Promise<void> {
  if (Platform.OS === 'web') {
    if (value === null) memory.delete(key);
    else memory.set(key, value);
    return;
  }
  if (value === null) await SecureStore.deleteItemAsync(key);
  else await SecureStore.setItemAsync(key, value);
}

export const storage = {
  getToken: () => read(TOKEN_KEY),
  setToken: (token: string | null) => write(TOKEN_KEY, token),
  getLastLogin: () => read(LOGIN_KEY),
  setLastLogin: (login: string | null) => write(LOGIN_KEY, login),
  getPushToken: () => read(PUSH_KEY),
  setPushToken: (token: string | null) => write(PUSH_KEY, token),
  /** Lembretes no aparelho ligados (padrão: sim). */
  async getReminders(): Promise<boolean> {
    return (await read(REMINDERS_KEY)) !== '0';
  },
  setReminders: (on: boolean) => write(REMINDERS_KEY, on ? null : '0'),
  /** Checklist de chegada da visita em andamento (só a última: prédio e dia). */
  getArrival: () => read(ARRIVAL_KEY),
  setArrival: (value: string | null) => write(ARRIVAL_KEY, value),
};
