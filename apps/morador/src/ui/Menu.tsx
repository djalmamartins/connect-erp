import Ionicons from '@expo/vector-icons/Ionicons';
import { router, type Href } from 'expo-router';
import type { ComponentProps } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { isFeatureOn, type Feature, type Me } from '../api/resident';
import { colors, fonts, radius, space } from '../theme';

export type MenuItem = {
  label: string;
  hint: string;
  icon: ComponentProps<typeof Ionicons>['name'];
  color: string;
  /** Sem href = ainda não existe no app ("Em breve"). */
  href?: Href;
  feature?: Feature;
};

/** Itens que o condomínio ligou (os sem função sempre aparecem). */
export function visibleItems(items: MenuItem[], me: Me | null): MenuItem[] {
  return items.filter((i) => !i.feature || isFeatureOn(me, i.feature));
}

/** Lista agrupada no estilo dos Ajustes do iPhone (Resolva fácil, Unidade, Perfil). */
export function MenuGroup({ items, title }: { items: MenuItem[]; title?: string }) {
  if (items.length === 0) return null;
  return (
    <View style={{ gap: space[2] }}>
      {title ? <Text style={styles.title}>{title}</Text> : null}
      <View style={styles.group}>
        {items.map((item, i) => {
          const soon = !item.href;
          return (
            <Pressable
              key={item.label}
              onPress={item.href ? () => router.push(item.href as Href) : undefined}
              disabled={soon}
              accessibilityRole="button"
              accessibilityLabel={soon ? `${item.label}, em breve` : item.label}
              accessibilityState={{ disabled: soon }}
              style={({ pressed }) => [styles.item, pressed && !soon && { backgroundColor: colors.surfaceMuted }]}
            >
              <View style={[styles.icon, { backgroundColor: item.color }, soon && { opacity: 0.45 }]}>
                <Ionicons name={item.icon} size={18} color="#ffffff" />
              </View>
              <View style={[styles.body, i < items.length - 1 && styles.separator]}>
                <View style={{ flex: 1 }}>
                  <Text style={[styles.label, soon && { color: colors.muted }]}>{item.label}</Text>
                  <Text style={styles.hint}>{item.hint}</Text>
                </View>
                {soon ? (
                  <View style={styles.soon}>
                    <Text style={styles.soonText}>Em breve</Text>
                  </View>
                ) : (
                  <Ionicons name="chevron-forward" size={18} color={colors.faint} />
                )}
              </View>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  title: { fontFamily: fonts.semibold, fontSize: 13, letterSpacing: 0.5, textTransform: 'uppercase', color: colors.muted, marginTop: space[2], marginLeft: space[1] },
  group: { backgroundColor: colors.surface, borderRadius: radius.xl, borderWidth: StyleSheet.hairlineWidth, borderColor: colors.border, overflow: 'hidden' },
  item: { flexDirection: 'row', alignItems: 'center', paddingLeft: space[4], gap: space[3] },
  icon: { width: 34, height: 34, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  body: { flex: 1, flexDirection: 'row', alignItems: 'center', paddingVertical: 13, paddingRight: space[4], gap: space[2] },
  separator: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  label: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  hint: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted, marginTop: 1 },
  soon: { paddingHorizontal: 8, height: 22, borderRadius: 11, backgroundColor: colors.surfaceMuted, borderWidth: StyleSheet.hairlineWidth, borderColor: colors.border, justifyContent: 'center' },
  soonText: { fontFamily: fonts.semibold, fontSize: 11, color: colors.muted },
});
