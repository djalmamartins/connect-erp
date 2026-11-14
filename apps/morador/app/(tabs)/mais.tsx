import Ionicons from '@expo/vector-icons/Ionicons';
import { router, type Href } from 'expo-router';
import type { ComponentProps } from 'react';
import { Text, View } from 'react-native';
import { isFeatureOn, type Feature } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { confirm } from '../../src/lib/confirm';
import { colors, fonts, space } from '../../src/theme';
import { Body, Button, Card, Screen } from '../../src/ui/kit';

type Item = { label: string; hint: string; href: Href; icon: ComponentProps<typeof Ionicons>['name']; feature?: Feature };

const ITEMS: Item[] = [
  { label: 'Comunicados', hint: 'Avisos da administração', href: '/comunicados', icon: 'megaphone-outline', feature: 'announcements' },
  { label: 'Encomendas', hint: 'O que chegou na portaria', href: '/encomendas', icon: 'cube-outline', feature: 'deliveries' },
  { label: 'Visitantes', hint: 'Avise a portaria de quem vem', href: '/visitantes', icon: 'people-outline', feature: 'visitors' },
  { label: 'Documentos', hint: 'Convenção, regimento e atas', href: '/documentos', icon: 'document-text-outline', feature: 'documents' },
  { label: 'Assembleias', hint: 'Datas, pauta e resultados', href: '/assembleias', icon: 'podium-outline', feature: 'assemblies' },
  { label: 'Pets e veículos', hint: 'Cadastro da sua unidade', href: '/pets-e-veiculos', icon: 'paw-outline' },
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
    <Screen>
      {items.map((item) => (
        <Card key={item.label} onPress={() => router.push(item.href)} accessibilityLabel={item.label}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: space[3] }}>
            <Ionicons name={item.icon} size={24} color={colors.brand} />
            <View style={{ flex: 1 }}>
              <Text style={{ fontFamily: fonts.semibold, fontSize: 16, color: colors.text }}>{item.label}</Text>
              <Body muted>{item.hint}</Body>
            </View>
            <Ionicons name="chevron-forward" size={18} color={colors.faint} />
          </View>
        </Card>
      ))}
      <View style={{ gap: space[2], marginTop: space[4] }}>
        {many ? <Button title="Trocar de unidade" variant="secondary" onPress={() => router.push('/unidade')} /> : null}
        <Button title="Sair" variant="danger" onPress={sair} />
      </View>
    </Screen>
  );
}
