import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * Token da sessão do aparelho guardado no Keychain (iOS) / Keystore (Android).
 * Na web (só para desenvolvimento) fica apenas na memória da aba.
 */
const TOKEN_KEY = 'connect.morador.token';
const RESIDENT_KEY = 'connect.morador.resident_id';
const BIOMETRIC_KEY = 'connect.morador.biometric';
const LOGIN_KEY = 'connect.morador.last_login';
const HIDE_KEY = 'connect.morador.hide_values';
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
  /** Pedir Face ID / digital ao abrir o app (escolha do morador, só neste aparelho). */
  async getBiometric(): Promise<boolean> {
    return (await read(BIOMETRIC_KEY)) === '1';
  },
  setBiometric: (on: boolean) => write(BIOMETRIC_KEY, on ? '1' : null),
  /** Último e-mail ou CPF usado, para já vir preenchido no login. A senha nunca é guardada. */
  getLastLogin: () => read(LOGIN_KEY),
  setLastLogin: (login: string | null) => write(LOGIN_KEY, login),
  /** Esconder os valores no Início (o olho do cartão verde). */
  async getHideValues(): Promise<boolean> {
    return (await read(HIDE_KEY)) === '1';
  },
  setHideValues: (on: boolean) => write(HIDE_KEY, on ? '1' : null),
};
