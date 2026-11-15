import { Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold, useFonts } from '@expo-google-fonts/inter';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useCallback, useState } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { ProfileProvider } from '../src/auth/ProfileContext';
import { colors } from '../src/theme';
import { DialogHost } from '../src/ui/Dialog';
import { IntroSplash } from '../src/ui/IntroSplash';
import { Loading } from '../src/ui/kit';
import { LockScreen } from '../src/ui/LockScreen';

function RootStack() {
  const { status } = useAuth();
  if (status === 'loading') {
    return <Loading />;
  }
  if (status === 'locked') {
    return <LockScreen />;
  }
  const signedIn = status === 'signedIn';
  return (
    <Stack
      screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.canvas } }}
    >
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="login" />
      </Stack.Protected>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="boas-vindas" options={{ gestureEnabled: false, animation: 'fade' }} />
        <Stack.Screen name="comunicados" />
        <Stack.Screen name="prestacao-de-contas" />
        <Stack.Screen name="encomendas" />
        <Stack.Screen name="visitantes" />
        <Stack.Screen name="documentos" />
        <Stack.Screen name="assembleias/index" />
        <Stack.Screen name="assembleias/[id]" />
        <Stack.Screen name="pets-e-veiculos" />
        <Stack.Screen name="seguranca" />
        <Stack.Screen name="reservar/[id]" />
        <Stack.Screen name="financeiro" />
        <Stack.Screen name="reservas" />
        <Stack.Screen name="ocorrencias" />
        <Stack.Screen name="avisos" />
        <Stack.Screen name="trocar-unidade" options={{ presentation: 'modal' }} />
      </Stack.Protected>
    </Stack>
  );
}

// A tela nativa (símbolo dourado no verde) fica até a abertura animada estar pronta para desenhar.
SplashScreen.preventAutoHideAsync().catch(() => undefined);

export default function RootLayout() {
  const [loaded] = useFonts({ Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold });
  const [intro, setIntro] = useState(true);
  const hideNative = useCallback(() => {
    SplashScreen.hideAsync().catch(() => undefined);
  }, []);
  const finishIntro = useCallback(() => setIntro(false), []);
  if (!loaded) {
    return null;
  }
  return (
    <SafeAreaProvider>
      <StatusBar style="dark" />
      <AuthProvider>
        <ProfileProvider>
          <RootStack />
        </ProfileProvider>
      </AuthProvider>
      <DialogHost />
      {intro ? (
        <>
          <StatusBar style="light" />
          <IntroSplash onReady={hideNative} onDone={finishIntro} />
        </>
      ) : null}
    </SafeAreaProvider>
  );
}
