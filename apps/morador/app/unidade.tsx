import Ionicons from '@expo/vector-icons/Ionicons';
import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import type { ResidentLink } from '../src/api/resident';
import { useAuth } from '../src/auth/AuthContext';
import { colors, fonts, radius, space } from '../src/theme';
import { Body, Screen } from '../src/ui/kit';

/**
 * Troca de condomínio/unidade (abre ao tocar no nome do condomínio no Início). Agrupa as unidades
 * por condomínio; escolhendo uma, o app inteiro passa a mostrar os dados dela.
 */
export default function Unidade() {
  const { me, residentId, selectResident } = useAuth();
  const groups = new Map<string, ResidentLink[]>();
  for (const link of me?.links ?? []) {
    const list = groups.get(link.condominium_name) ?? [];
    list.push(link);
    groups.set(link.condominium_name, list);
  }

  async function choose(id: number) {
    if (id !== residentId) await selectResident(id);
    router.back();
  }

  return (
    <Screen title="Seus condomínios" subtitle="Escolha qual quer ver no app" back="close">
      {[...groups.entries()].map(([condo, links]) => (
        <View key={condo} style={{ gap: space[2] }}>
          <View style={styles.condoHead}>
            <View style={styles.condoIcon}>
              <Ionicons name="business" size={16} color={colors.accent} />
            </View>
            <Text style={styles.condoName}>{condo}</Text>
          </View>
          <View style={styles.group}>
            {links.map((link, i) => {
              const active = link.resident_id === residentId;
              return (
                <Pressable
                  key={link.resident_id}
                  onPress={() => choose(link.resident_id)}
                  accessibilityRole="button"
                  accessibilityState={{ selected: active }}
                  accessibilityLabel={`${condo}, ${link.unit_label}, ${link.relationship_label}`}
                  style={({ pressed }) => [styles.row, i < links.length - 1 && styles.separator, pressed && { backgroundColor: colors.surfaceMuted }]}
                >
                  <Ionicons name="home-outline" size={20} color={active ? colors.brand : colors.muted} />
                  <View style={{ flex: 1 }}>
                    <Text style={[styles.unit, active && { color: colors.brand }]}>{link.unit_label}</Text>
                    <Body muted>{link.relationship_label}</Body>
                  </View>
                  {active ? <Ionicons name="checkmark-circle" size={22} color={colors.brand} /> : <Ionicons name="chevron-forward" size={18} color={colors.faint} />}
                </Pressable>
              );
            })}
          </View>
        </View>
      ))}
    </Screen>
  );
}

const styles = StyleSheet.create({
  condoHead: { flexDirection: 'row', alignItems: 'center', gap: space[2], marginTop: space[2] },
  condoIcon: { width: 28, height: 28, borderRadius: 8, backgroundColor: colors.sidebar, alignItems: 'center', justifyContent: 'center' },
  condoName: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  group: { backgroundColor: colors.surface, borderRadius: radius.xl, borderWidth: StyleSheet.hairlineWidth, borderColor: colors.border, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: space[3], paddingHorizontal: space[4], paddingVertical: 14 },
  separator: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  unit: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
});
