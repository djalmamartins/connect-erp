import Constants from 'expo-constants';

/**
 * Endereço do servidor do Connect ERP. Ordem: EXPO_PUBLIC_API_URL (por build ou .env local),
 * depois expo.extra.apiBaseUrl do app.json. Só https fora do laboratório.
 */
export function apiBaseUrl(): string {
  const fromEnv = process.env.EXPO_PUBLIC_API_URL;
  const fromConfig = (Constants.expoConfig?.extra as { apiBaseUrl?: string } | undefined)?.apiBaseUrl;
  return (fromEnv || fromConfig || 'https://app.connect.lab').replace(/\/+$/, '');
}
