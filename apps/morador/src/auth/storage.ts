import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * Token da sessão do aparelho guardado no Keychain (iOS) / Keystore (Android).
 * Na web (só para desenvolvimento) fica apenas na memória da aba.
 */
const TOKEN_KEY = 'connect.morador.token';
const RESIDENT_KEY = 'connect.morador.resident_id';
const memory = new Map<string, string>();

async function read(key: string): Promise<string | null> {
  if (Platform.OS === 'web') {
    return memory.get(key) ?? null;
  }
  return SecureStore.getItemAsync(key);
}

async function write(key: string, value: string | null): Promise<void> {
  if (Platform.OS === 'web') {
    if (value === null) {
      memory.delete(key);
    } else {
      memory.set(key, value);
    }
    return;
  }
  if (value === null) {
    await SecureStore.deleteItemAsync(key);
  } else {
    await SecureStore.setItemAsync(key, value);
  }
}

export const tokenStorage = {
  getToken: () => read(TOKEN_KEY),
  setToken: (token: string | null) => write(TOKEN_KEY, token),
  async getResidentId(): Promise<number | null> {
    const raw = await read(RESIDENT_KEY);
    const id = raw ? Number(raw) : NaN;
    return Number.isInteger(id) && id > 0 ? id : null;
  },
  setResidentId: (id: number | null) => write(RESIDENT_KEY, id === null ? null : String(id)),
};
