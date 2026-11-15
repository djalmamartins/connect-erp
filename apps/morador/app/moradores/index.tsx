import Ionicons from '@expo/vector-icons/Ionicons';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState, type ComponentProps } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { HouseholdMember } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { date } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Empty, ErrorBox, IconRow, Loading, Screen, SectionTitle } from '../../src/ui/kit';
import { dialog } from '../../src/ui/Dialog';

type IconName = ComponentProps<typeof Ionicons>['name'];

const LOOK: Record<string, { icon: IconName; tint: string; bg: string }> = {
  owner: { icon: 'key', tint: '#8a6d1f', bg: colors.accentSoft },
  tenant: { icon: 'home', tint: '#2f7bd8', bg: colors.infoSoft },
  resident: { icon: 'people', tint: '#0f7a6a', bg: colors.successSoft },
  financial: { icon: 'wallet', tint: '#6d4bd8', bg: '#f0ecfd' },
};

/**
 * Moradores da unidade (revisão de 15/11): quem mora, cadastro de morador e inquilino e bloqueio
 * do inquilino pelo proprietário, que pode já informar o novo. Regras no servidor (função "household").
 */
export default function Moradores() {
  const { api, me, residentId } = useAuth();
  const list = useResource(() => api.household(), [residentId]);
  const [blocking, setBlocking] = useState<number | null>(null);
  const reloadRef = useRef(list.reload);
  reloadRef.current = list.reload;
  const focused = useRef(false);

  // Volta do cadastro com a lista atualizada (a primeira carga já vem do useResource).
  useFocusEffect(
    useCallback(() => {
      if (focused.current) void reloadRef.current();
      focused.current = true;
    }, []),
  );

  async function block(m: HouseholdMember) {
    const ok = await dialog.confirm({
      title: `Bloquear ${m.name.split(' ')[0]}?`,
      message: 'O vínculo de inquilino termina hoje e a pessoa perde o acesso ao app do condomínio. A administração fica sabendo. Isso não pode ser desfeito pelo app.',
      confirm: 'Bloquear inquilino',
      icon: 'lock-closed',
    });
    if (!ok) return;
    setBlocking(m.id);
    try {
      await api.blockTenant(m.id);
      await list.reload();
      const next = await dialog.confirm({
        title: 'Inquilino bloqueado',
        message: 'Quer cadastrar agora o novo inquilino da unidade?',
        confirm: 'Cadastrar novo inquilino',
        cancel: 'Agora não',
        tone: 'success',
        icon: 'checkmark-circle',
      });
      if (next) router.push('/moradores/novo?tipo=tenant');
    } catch (e) {
      await dialog.notice({ title: 'Não deu para bloquear', message: e instanceof ApiError ? e.message : 'Tente de novo em instantes.', tone: 'error' });
    } finally {
      setBlocking(null);
    }
  }

  const canAdd = list.data?.meta.can_add ?? [];
  const active = list.data?.data.filter((m) => m.status === 'active') ?? [];
  const past = list.data?.data.filter((m) => m.status !== 'active') ?? [];

  return (
    <Screen title="Moradores" subtitle={me?.current?.unit_label} back refreshing={list.refreshing} onRefresh={list.reload}>
      {list.loading && !list.data ? (
        <Loading />
      ) : list.error ? (
        <ErrorBox error={list.error} onRetry={list.reload} />
      ) : (
        <>
          {canAdd.length > 0 ? (
            <View style={styles.actions}>
              {canAdd.includes('resident') ? <AddButton icon="person-add" label="Novo morador" onPress={() => router.push('/moradores/novo?tipo=resident')} /> : null}
              {canAdd.includes('tenant') ? <AddButton icon="home" label="Novo inquilino" onPress={() => router.push('/moradores/novo?tipo=tenant')} /> : null}
            </View>
          ) : (
            <Body muted>Quem cadastra pessoas na unidade é o proprietário ou o inquilino. Se faltar alguém, fale com eles ou com a administração.</Body>
          )}
          {active.length === 0 ? <Empty text="Ninguém cadastrado nesta unidade." /> : null}
          {active.map((m) => (
            <MemberRow key={m.id} m={m} busy={blocking === m.id} onBlock={() => block(m)} />
          ))}
          {past.length > 0 ? <SectionTitle>Vínculos encerrados</SectionTitle> : null}
          {past.map((m) => (
            <MemberRow key={m.id} m={m} busy={false} />
          ))}
        </>
      )}
    </Screen>
  );
}

function MemberRow({ m, busy, onBlock }: { m: HouseholdMember; busy: boolean; onBlock?: () => void }) {
  const look = LOOK[m.relationship] ?? LOOK.resident!;
  const ended = m.status !== 'active';
  const since = ended ? (m.ends_on ? `até ${date(m.ends_on)}` : 'encerrado') : m.starts_on ? `desde ${date(m.starts_on)}` : '';
  return (
    <IconRow
      icon={ended ? 'lock-closed' : look.icon}
      tint={ended ? colors.faint : look.tint}
      bg={ended ? colors.surfaceMuted : look.bg}
      title={m.is_me ? `${m.name} (você)` : m.name}
      subtitle={[m.relationship_label, since].filter(Boolean).join(' · ')}
      right={<View />}
    >
      {m.can_block && onBlock && !ended ? (
        <Pressable
          onPress={onBlock}
          disabled={busy}
          accessibilityRole="button"
          accessibilityLabel={`Bloquear ${m.name}`}
          style={({ pressed }) => [styles.block, (pressed || busy) && { opacity: 0.6 }]}
        >
          <Ionicons name="lock-closed-outline" size={16} color={colors.danger} />
          <Text style={styles.blockText}>{busy ? 'Bloqueando…' : 'Bloquear inquilino'}</Text>
        </Pressable>
      ) : null}
    </IconRow>
  );
}

function AddButton({ icon, label, onPress }: { icon: IconName; label: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} accessibilityRole="button" style={({ pressed }) => [styles.add, pressed && { opacity: 0.8 }]}>
      <Ionicons name={icon} size={18} color={colors.onBrand} />
      <Text style={styles.addText}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  actions: { flexDirection: 'row', gap: space[2] },
  add: {
    flex: 1,
    minHeight: 48,
    borderRadius: radius.pill,
    backgroundColor: colors.brand,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space[2],
    paddingHorizontal: space[3],
  },
  addText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.onBrand },
  block: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 6,
    marginTop: space[3],
    marginLeft: 42 + space[3],
    paddingHorizontal: space[3],
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.dangerSoft,
  },
  blockText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.danger },
});
