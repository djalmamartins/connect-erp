import { Redirect } from 'expo-router';
import { Tabs } from 'expo-router/js-tabs';
import { useProfile } from '../../src/auth/ProfileContext';
import { colors } from '../../src/theme';
import { FloatingTabBar } from '../../src/ui/FloatingTabBar';

export default function TabsLayout() {
  const { onboarding } = useProfile();
  // Primeiro acesso: boas-vindas com foto e dicas antes do Início.
  if (onboarding && !onboarding.completed) {
    return <Redirect href="/boas-vindas" />;
  }
  return (
    <Tabs
      tabBar={(props) => <FloatingTabBar {...props} />}
      screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.canvas } }}
    >
      <Tabs.Screen name="index" options={{ title: 'Início' }} />
      <Tabs.Screen name="resolva" options={{ title: 'Resolva fácil' }} />
      <Tabs.Screen name="unidade" options={{ title: 'Unidade' }} />
      <Tabs.Screen name="perfil" options={{ title: 'Perfil' }} />
    </Tabs>
  );
}
