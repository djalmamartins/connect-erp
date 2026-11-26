import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/** Token da sessão do aparelho no Keychain (iOS) / Keystore (Android). Na web (só desenvolvimento) fica na memória. */
const TOKEN_KEY = 'connect.sac.token';
const LOGIN_KEY = 'connect.sac.last_login';
const PUSH_KEY = 'connect.sac.push_token';
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
  /** Último e-mail ou CPF, para vir preenchido. A senha nunca é guardada. */
  getLastLogin: () => read(LOGIN_KEY),
  setLastLogin: (login: string | null) => write(LOGIN_KEY, login),
  getPushToken: () => read(PUSH_KEY),
  setPushToken: (token: string | null) => write(PUSH_KEY, token),
};
