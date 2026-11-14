import { Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold, useFonts } from '@expo-google-fonts/inter';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { colors, fonts } from '../src/theme';
import { Loading } from '../src/ui/kit';

function RootStack() {
  const { status } = useAuth();
  if (status === 'loading') {
    return <Loading />;
  }
  const signedIn = status === 'signedIn';
  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: colors.sidebar },
        headerTintColor: '#ffffff',
        headerTitleStyle: { fontFamily: fonts.semibold },
        contentStyle: { backgroundColor: colors.canvas },
        headerBackTitle: 'Voltar',
      }}
    >
      <Stack.Protected guard={!signedIn}>
        <Stack.Screen name="login" options={{ headerShown: false }} />
      </Stack.Protected>
      <Stack.Protected guard={signedIn}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="comunicados" options={{ title: 'Comunicados' }} />
        <Stack.Screen name="prestacao-de-contas" options={{ title: 'Prestação de contas' }} />
        <Stack.Screen name="encomendas" options={{ title: 'Encomendas' }} />
        <Stack.Screen name="visitantes" options={{ title: 'Visitantes' }} />
        <Stack.Screen name="documentos" options={{ title: 'Documentos' }} />
        <Stack.Screen name="assembleias/index" options={{ title: 'Assembleias' }} />
        <Stack.Screen name="assembleias/[id]" options={{ title: 'Assembleia' }} />
        <Stack.Screen name="pets-e-veiculos" options={{ title: 'Pets e veículos' }} />
        <Stack.Screen name="unidade" options={{ title: 'Trocar de unidade', presentation: 'modal' }} />
      </Stack.Protected>
    </Stack>
  );
}

export default function RootLayout() {
  const [loaded] = useFonts({ Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold });
  if (!loaded) {
    return null;
  }
  return (
    <SafeAreaProvider>
      <StatusBar style="light" />
      <AuthProvider>
        <RootStack />
      </AuthProvider>
    </SafeAreaProvider>
  );
}
