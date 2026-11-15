import Ionicons from '@expo/vector-icons/Ionicons';
import { router } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../../src/auth/AuthContext';
import { colors, fonts, space } from '../../src/theme';
import { Card, Screen } from '../../src/ui/kit';
import { MenuGroup, visibleItems, type MenuItem } from '../../src/ui/Menu';

/** Unidade: quem mora, o que tem e quem entra (revisão de 15/11). */
const PEOPLE: MenuItem[] = [
  { label: 'Moradores', hint: 'Cadastre moradores e inquilinos', icon: 'people', color: '#0f7a6a' },
  { label: 'Autorizar entrada', hint: 'Avise a portaria de quem vem', icon: 'person-add', color: '#5b6bd6', href: '/visitantes', feature: 'visitors' },
];
const THINGS: MenuItem[] = [
  { label: 'Veículos', hint: 'Carros e motos da unidade', icon: 'car', color: '#2f7bd8', href: '/pets-e-veiculos' },
  { label: 'Pets', hint: 'Animais da unidade', icon: 'paw', color: '#c2477a', href: '/pets-e-veiculos' },
  { label: 'Correspondências', hint: 'O que chegou na portaria', icon: 'mail', color: '#8a6d1f', href: '/encomendas', feature: 'deliveries' },
];
const HELP: MenuItem[] = [
  { label: 'Consultar chamados', hint: 'Acompanhe o que você pediu', icon: 'chatbubbles', color: '#c2611f', href: '/ocorrencias', feature: 'occurrences' },
];

export default function Unidade() {
  const { me } = useAuth();
  const current = me?.current;
  const many = (me?.links.length ?? 0) > 1;
  return (
    <Screen title="Unidade" tab>
      {current ? (
        <Card onPress={many ? () => router.push('/trocar-unidade') : undefined} accessibilityLabel={many ? 'Trocar de unidade' : undefined} style={styles.unit}>
          <View style={styles.unitIcon}>
            <Ionicons name="home" size={22} color={colors.accent} />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.unitLabel}>{current.unit_label}</Text>
            <Text style={styles.condo}>{current.condominium_name}</Text>
          </View>
          {many ? <Ionicons name="swap-horizontal" size={20} color={colors.muted} /> : null}
        </Card>
      ) : null}
      <MenuGroup title="Pessoas" items={visibleItems(PEOPLE, me)} />
      <MenuGroup title="Da unidade" items={visibleItems(THINGS, me)} />
      <MenuGroup title="Atendimento" items={visibleItems(HELP, me)} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  unit: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  unitIcon: { width: 48, height: 48, borderRadius: 14, backgroundColor: colors.sidebar, alignItems: 'center', justifyContent: 'center' },
  unitLabel: { fontFamily: fonts.bold, fontSize: 18, color: colors.text },
  condo: { fontFamily: fonts.regular, fontSize: 14, color: colors.muted },
});
