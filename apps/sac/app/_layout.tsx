import { Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold, useFonts } from '@expo-google-fonts/inter';
import * as Notifications from 'expo-notifications';
import { router, Stack, type Href } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { Platform } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { configurePushDisplay } from '../src/lib/push';
import { pushRoute } from '../src/lib/tickets';
import { colors } from '../src/theme';
import { DialogHost } from '../src/ui/Dialog';
import { Loading } from '../src/ui/kit';

configurePushDisplay();

/** Toque no push abre o chamado, inclusive com o app fechado. */
function usePushNavigation(signedIn: boolean) {
  useEffect(() => {
    if (!signedIn || Platform.OS === 'web') return;
    const open = (data: unknown) => {
      const route = pushRoute(data);
      if (route) router.push(route as Href);
    };
    const last = Notifications.getLastNotificationResponse();
    if (last) {
      open(last.notification.request.content.data);
      Notifications.clearLastNotificationResponse();
    }
    const sub = Notifications.addNotificationResponseReceivedListener((response) => open(response.notification.request.content.data));
    return () => sub.remove();
  }, [signedIn]);
}

function RootStack() {
  const { status } = useAuth();
  usePushNavigation(status === 'signedIn');
  if (status === 'loading') return <Loading />;
  const signedIn = status === 'signedIn';
  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.canvas } }}>
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="login" />
      </Stack.Protected>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="chamados/[id]" />
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
        <RootStack />
      </AuthProvider>
      <DialogHost />
    </SafeAreaProvider>
  );
}
