import Ionicons from '@expo/vector-icons/Ionicons';
import { router, type Href } from 'expo-router';
import type { ComponentProps } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { isFeatureOn, type Feature } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { confirm } from '../../src/lib/confirm';
import { initials } from '../../src/lib/format';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Button, Card, Screen } from '../../src/ui/kit';

type Item = { label: string; hint: string; href: Href; icon: ComponentProps<typeof Ionicons>['name']; feature?: Feature; color: string };

const ITEMS: Item[] = [
  { label: 'Comunicados', color: '#e8833a', hint: 'Avisos da administração', href: '/comunicados', icon: 'megaphone-outline', feature: 'announcements' },
  { label: 'Encomendas', color: '#8a6d1f', hint: 'O que chegou na portaria', href: '/encomendas', icon: 'cube-outline', feature: 'deliveries' },
  { label: 'Visitantes', color: '#2f7bd8', hint: 'Avise a portaria de quem vem', href: '/visitantes', icon: 'people-outline', feature: 'visitors' },
  { label: 'Documentos', color: '#5b6bd6', hint: 'Convenção, regimento e atas', href: '/documentos', icon: 'document-text-outline', feature: 'documents' },
  { label: 'Assembleias', color: '#07884c', hint: 'Datas, pauta e resultados', href: '/assembleias', icon: 'podium-outline', feature: 'assemblies' },
  { label: 'Pets e veículos', color: '#c2477a', hint: 'Cadastro da sua unidade', href: '/pets-e-veiculos', icon: 'paw-outline' },
];

export default function Mais() {
  const { me, logout } = useAuth();
  const items = ITEMS.filter((i) => !i.feature || isFeatureOn(me, i.feature));
  const many = (me?.links.length ?? 0) > 1;

  async function sair() {
    if (await confirm('Sair', 'Quer sair do app neste aparelho?', 'Sair')) {
      await logout();
    }
  }

  return (
    <Screen title="Mais" tab>
      {me?.current ? (
        <Card style={styles.profile}>
          <View style={styles.avatar}>
            <Text style={styles.avatarText}>{initials(me.current.name ?? me.user.name)}</Text>
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.name}>{me.current.name ?? me.user.name}</Text>
            <Body muted>
              {me.current.unit_label} · {me.current.condominium_name}
            </Body>
          </View>
        </Card>
      ) : null}
      {items.length > 0 ? (
        <View style={styles.group}>
          {items.map((item, i) => (
            <Pressable
              key={item.label}
              onPress={() => router.push(item.href)}
              accessibilityRole="button"
              accessibilityLabel={item.label}
              style={({ pressed }) => [styles.item, pressed && { backgroundColor: colors.surfaceMuted }]}
            >
              <View style={[styles.icon, { backgroundColor: item.color }]}>
                <Ionicons name={item.icon} size={18} color="#ffffff" />
              </View>
              <View style={[styles.itemBody, i < items.length - 1 && styles.separator]}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.itemLabel}>{item.label}</Text>
                  <Text style={styles.itemHint}>{item.hint}</Text>
                </View>
                <Ionicons name="chevron-forward" size={18} color={colors.faint} />
              </View>
            </Pressable>
          ))}
        </View>
      ) : null}
      <View style={{ gap: space[2], marginTop: space[3] }}>
        {many ? <Button title="Trocar de unidade" variant="secondary" onPress={() => router.push('/unidade')} /> : null}
        <Button title="Sair" variant="danger" onPress={sair} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  profile: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  avatar: {
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: colors.sidebar,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: colors.accent,
  },
  avatarText: { fontFamily: fonts.bold, fontSize: 17, color: colors.accent },
  name: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  group: {
    backgroundColor: colors.surface,
    borderRadius: radius.xl,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
    overflow: 'hidden',
  },
  item: { flexDirection: 'row', alignItems: 'center', paddingLeft: space[4], gap: space[3] },
  icon: { width: 32, height: 32, borderRadius: 9, alignItems: 'center', justifyContent: 'center' },
  itemBody: { flex: 1, flexDirection: 'row', alignItems: 'center', paddingVertical: 12, paddingRight: space[4], gap: space[2] },
  separator: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  itemLabel: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  itemHint: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted, marginTop: 1 },
});
