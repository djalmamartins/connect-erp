import Ionicons from '@expo/vector-icons/Ionicons';
import { BlurView } from 'expo-blur';
import type { BottomTabBarProps } from 'expo-router/js-tabs';
import type { ComponentProps } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, fonts, space } from '../theme';

type IconName = ComponentProps<typeof Ionicons>['name'];

/** Ícone de cada aba: contorno quando parada, cheio quando aberta. */
export const TAB_ICONS: Record<string, [IconName, IconName]> = {
  index: ['home-outline', 'home'],
  financeiro: ['wallet-outline', 'wallet'],
  reservas: ['calendar-outline', 'calendar'],
  ocorrencias: ['chatbubble-ellipses-outline', 'chatbubble-ellipses'],
  mais: ['grid-outline', 'grid'],
};

/**
 * Barra de baixo flutuante e translúcida (vidro), solta das bordas,
 * no estilo das versões novas do iOS. Fica por cima do conteúdo, então
 * as telas de aba deixam espaço no fim (Screen com `tab`).
 */
export function FloatingTabBar({ state, descriptors, emitter, navigateToTab, insets }: BottomTabBarProps) {
  return (
    <View pointerEvents="box-none" style={[styles.wrap, { paddingBottom: Math.max(insets.bottom, space[3]) }]}>
      <View style={styles.bar} accessibilityRole="tablist">
        <BlurView intensity={80} tint="light" style={StyleSheet.absoluteFill} />
        <View style={[StyleSheet.absoluteFill, styles.glass]} />
        {state.routes.map((route, index) => {
          const focused = state.index === index;
          const options = descriptors[route.key]?.options ?? {};
          const label = typeof options.title === 'string' ? options.title : route.name;
          const [outline, filled] = TAB_ICONS[route.name] ?? ['ellipse-outline', 'ellipse'];
          const onPress = () => {
            const event = emitter.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
            if (!focused && !event.defaultPrevented) {
              navigateToTab(route.key);
            }
          };
          return (
            <Pressable
              key={route.key}
              onPress={onPress}
              onLongPress={() => emitter.emit({ type: 'tabLongPress', target: route.key })}
              accessibilityRole="tab"
              accessibilityLabel={label}
              accessibilityState={{ selected: focused }}
              style={({ pressed }) => [styles.item, pressed && { opacity: 0.6 }]}
            >
              <View style={[styles.pill, focused && styles.pillActive]}>
                <Ionicons name={focused ? filled : outline} size={23} color={focused ? colors.brandStrong : colors.text} />
                <Text style={[styles.label, focused && styles.labelActive]} numberOfLines={1}>
                  {label}
                </Text>
              </View>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { position: 'absolute', left: 0, right: 0, bottom: 0, paddingHorizontal: space[3] },
  bar: {
    flexDirection: 'row',
    height: 66,
    borderRadius: 33,
    padding: 5,
    overflow: 'hidden',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: 'rgba(255,255,255,0.9)',
    ...Platform.select({
      ios: { shadowColor: '#0d2e27', shadowOpacity: 0.16, shadowRadius: 22, shadowOffset: { width: 0, height: 10 } },
      android: { elevation: 10 },
      default: { boxShadow: '0 10px 28px rgba(13,46,39,0.18)' },
    }),
  },
  // Android sem blur forte: um branco um pouco mais denso mantém a leitura.
  glass: { backgroundColor: Platform.OS === 'android' ? 'rgba(255,255,255,0.86)' : 'rgba(255,255,255,0.55)' },
  item: { flex: 1 },
  pill: { flex: 1, borderRadius: 28, alignItems: 'center', justifyContent: 'center', gap: 2 },
  pillActive: { backgroundColor: 'rgba(7,136,76,0.13)' },
  label: { fontFamily: fonts.medium, fontSize: 10.5, color: colors.text },
  labelActive: { fontFamily: fonts.semibold, color: colors.brandStrong },
});
