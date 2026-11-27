import { Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold, useFonts } from '@expo-google-fonts/inter';
import * as Notifications from 'expo-notifications';
import { router, Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { Platform } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { PersonProvider } from '../src/auth/PersonContext';
import { configureDisplay } from '../src/lib/notify';
import { colors } from '../src/theme';
import { DialogHost } from '../src/ui/Dialog';
import { LockScreen } from '../src/ui/LockScreen';
import { Loading } from '../src/ui/kit';

configureDisplay();

/** Tocar num lembrete ou push abre o dia dele na aba Hoje. */
function useNotificationTap(signedIn: boolean) {
  useEffect(() => {
    if (!signedIn || Platform.OS === 'web') return;
    const open = (data: unknown) => {
      const date = (data as { date?: unknown } | null)?.date;
      router.navigate(typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date) ? { pathname: '/', params: { date } } : '/');
    };
    const last = Notifications.getLastNotificationResponse();
    if (last) {
      open(last.notification.request.content.data);
      Notifications.clearLastNotificationResponse();
    }
    const sub = Notifications.addNotificationResponseReceivedListener((r) => open(r.notification.request.content.data));
    return () => sub.remove();
  }, [signedIn]);
}

function RootStack() {
  const { status } = useAuth();
  useNotificationTap(status === 'signedIn');
  if (status === 'loading') return <Loading />;
  if (status === 'locked') return <LockScreen />;
  const signedIn = status === 'signedIn';
  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.canvas } }}>
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="login" />
      </Stack.Protected>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="visita/[id]" />
        <Stack.Screen name="vistoria/[id]" />
        <Stack.Screen name="adiar/[id]" />
      </Stack.Protected>
    </Stack>
  );
}

export default function RootLayout() {
  const [loaded] = useFonts({ Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold });
  if (!loaded) return null;
  return (
    <SafeAreaProvider>
      <StatusBar style="dark" />
      <AuthProvider>
        <PersonProvider>
          <RootStack />
        </PersonProvider>
      </AuthProvider>
      <DialogHost />
    </SafeAreaProvider>
  );
}
