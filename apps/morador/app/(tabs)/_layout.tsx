import Ionicons from '@expo/vector-icons/Ionicons';
import { Tabs } from 'expo-router';
import type { ComponentProps } from 'react';
import { colors, fonts } from '../../src/theme';

type IconName = ComponentProps<typeof Ionicons>['name'];

function icon(name: IconName) {
  return ({ color, size }: { color: unknown; size: number }) => <Ionicons name={name} color={typeof color === 'string' ? color : undefined} size={size} />;
}

export default function TabsLayout() {
  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: colors.sidebar },
        headerTintColor: '#ffffff',
        headerTitleStyle: { fontFamily: fonts.semibold },
        tabBarActiveTintColor: colors.brand,
        tabBarInactiveTintColor: colors.muted,
        tabBarLabelStyle: { fontFamily: fonts.medium, fontSize: 11 },
        sceneStyle: { backgroundColor: colors.canvas },
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Início', tabBarIcon: icon('home-outline') }} />
      <Tabs.Screen name="financeiro" options={{ title: 'Financeiro', tabBarIcon: icon('receipt-outline') }} />
      <Tabs.Screen name="reservas" options={{ title: 'Reservas', tabBarIcon: icon('calendar-outline') }} />
      <Tabs.Screen name="ocorrencias" options={{ title: 'Ocorrências', tabBarIcon: icon('chatbox-ellipses-outline') }} />
      <Tabs.Screen name="mais" options={{ title: 'Mais', tabBarIcon: icon('grid-outline') }} />
    </Tabs>
  );
}
