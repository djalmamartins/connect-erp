import Ionicons from '@expo/vector-icons/Ionicons';
import { router, type Href } from 'expo-router';
import type { ComponentProps } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import type { ResidentNotification } from '../api/resident';
import { date } from '../lib/format';
import { notificationRoute } from '../lib/notifications';
import { colors, fonts, space } from '../theme';
import { IconRow } from './kit';

type IconName = ComponentProps<typeof Ionicons>['name'];

const KIND: Record<string, { icon: IconName; tint: string; bg: string }> = {
  charge: { icon: 'barcode', tint: colors.danger, bg: colors.dangerSoft },
  delivery: { icon: 'cube', tint: '#8a6d1f', bg: '#f7efd9' },
  visit: { icon: 'person', tint: '#2f7bd8', bg: '#e6f0fc' },
  announcement: { icon: 'megaphone', tint: '#c2611f', bg: '#fdf0e6' },
  assembly: { icon: 'podium', tint: '#07884c', bg: colors.successSoft },
  reservation: { icon: 'calendar', tint: colors.brandStrong, bg: colors.brandSoft },
  reservation_approved: { icon: 'checkmark-circle', tint: colors.success, bg: colors.successSoft },
};

/** Um aviso do sininho, com o ícone do tipo; toca e abre a tela certa. */
export function NotificationRow({ item }: { item: ResidentNotification }) {
  const k = KIND[item.kind] ?? { icon: 'notifications' as IconName, tint: colors.brandStrong, bg: colors.brandSoft };
  return (
    <IconRow
      icon={k.icon}
      tint={k.tint}
      bg={k.bg}
      title={item.title}
      subtitle={`${item.detail} · ${date(item.at.slice(0, 10))}`}
      onPress={() => router.push(notificationRoute(item.path) as Href)}
    />
  );
}

/** Sino ao lado do perfil, com o número de avisos novos. */
export function Bell({ count }: { count: number }) {
  return (
    <Pressable
      onPress={() => router.push('/avisos')}
      accessibilityRole="button"
      accessibilityLabel={count > 0 ? `Notificações, ${count} nova${count > 1 ? 's' : ''}` : 'Notificações'}
      hitSlop={8}
      style={({ pressed }) => [styles.bell, pressed && { opacity: 0.7 }]}
    >
      <Ionicons name={count > 0 ? 'notifications' : 'notifications-outline'} size={22} color={colors.text} />
      {count > 0 ? (
        <View style={styles.badge}>
          <Text style={styles.badgeText}>{count > 9 ? '9+' : count}</Text>
        </View>
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  bell: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.surface,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 2,
    marginRight: space[2],
  },
  badge: {
    position: 'absolute',
    top: -2,
    right: -2,
    minWidth: 20,
    height: 20,
    borderRadius: 10,
    paddingHorizontal: 5,
    backgroundColor: colors.danger,
    borderWidth: 2,
    borderColor: colors.canvas,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { fontFamily: fonts.bold, fontSize: 11, color: '#ffffff' },
});
