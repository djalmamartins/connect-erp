import { Tabs } from 'expo-router/js-tabs';
import { colors } from '../../src/theme';
import { FloatingTabBar } from '../../src/ui/FloatingTabBar';

export default function TabsLayout() {
  return (
    <Tabs tabBar={(props) => <FloatingTabBar {...props} />} screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.canvas } }}>
      <Tabs.Screen name="index" options={{ title: 'Fila' }} />
      <Tabs.Screen name="novo" options={{ title: 'Novo' }} />
      <Tabs.Screen name="buscar" options={{ title: 'Buscar' }} />
      <Tabs.Screen name="perfil" options={{ title: 'Perfil' }} />
    </Tabs>
  );
}
