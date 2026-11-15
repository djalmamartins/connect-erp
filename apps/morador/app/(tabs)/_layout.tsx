import { Tabs } from 'expo-router/js-tabs';
import { colors } from '../../src/theme';
import { FloatingTabBar } from '../../src/ui/FloatingTabBar';

export default function TabsLayout() {
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
