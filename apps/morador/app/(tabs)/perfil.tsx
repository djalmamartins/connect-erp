import Ionicons from '@expo/vector-icons/Ionicons';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Switch, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import { useAuth } from '../../src/auth/AuthContext';
import { confirm } from '../../src/lib/confirm';
import { useProfile } from '../../src/auth/ProfileContext';
import { pickPhoto } from '../../src/lib/photo';
import { Avatar } from '../../src/ui/Avatar';
import { dialog } from '../../src/ui/Dialog';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Button, Card, Screen } from '../../src/ui/kit';
import { MenuGroup, type MenuItem } from '../../src/ui/Menu';

const ACCOUNT: MenuItem[] = [
  { label: 'Rever as dicas do app', color: '#c5a131', hint: 'O passo a passo do primeiro acesso', href: '/boas-vindas?rever=1', icon: 'bulb-outline' },
  { label: 'Verificação em duas etapas', color: '#0d2e27', hint: 'Opcional: um código além da senha', href: '/seguranca', icon: 'shield-checkmark-outline' },
];

/** Liga ou desliga a entrada por Face ID / digital neste aparelho. */
function BiometricRow() {
  const { biometric, setBiometric } = useAuth();
  const [busy, setBusy] = useState(false);
  if (!biometric.available) {
    return null;
  }
  async function toggle(on: boolean) {
    setBusy(true);
    await setBiometric(on);
    setBusy(false);
  }
  const label = `Entrar com ${biometric.label}`;
  return (
    <View style={[styles.group, { marginTop: space[3] }]}>
      <View style={styles.item}>
        <View style={[styles.icon, { backgroundColor: '#07884c' }]}>
          <Ionicons name="finger-print" size={18} color="#ffffff" />
        </View>
        <View style={styles.itemBody}>
          <View style={{ flex: 1 }}>
            <Text style={styles.itemLabel}>{label}</Text>
            <Text style={styles.itemHint}>Pede ao abrir o app, no lugar da senha</Text>
          </View>
          <Switch
            value={biometric.enabled}
            onValueChange={toggle}
            disabled={busy}
            accessibilityLabel={label}
            trackColor={{ true: colors.brand, false: colors.border }}
          />
        </View>
      </View>
    </View>
  );
}

export default function Perfil() {
  const { me, logout } = useAuth();
  const { onboarding, setPhoto, removePhoto } = useProfile();
  const many = (me?.links.length ?? 0) > 1;
  const [saving, setSaving] = useState(false);

  async function changePhoto() {
    const has = !!onboarding?.has_photo;
    const choice = await dialog.choose({
      title: has ? 'Trocar a foto' : 'Colocar uma foto',
      icon: 'camera',
      options: [
        { label: 'Tirar foto', icon: 'camera-outline' },
        { label: 'Escolher da galeria', icon: 'images-outline' },
        ...(has ? [{ label: 'Remover a foto', icon: 'trash-outline' as const, danger: true }] : []),
      ],
    });
    if (choice === null) {
      return;
    }
    setSaving(true);
    try {
      if (choice === 2) {
        await removePhoto();
        dialog.toast('Foto removida');
      } else {
        const file = await pickPhoto(choice === 0 ? 'camera' : 'library');
        if (file) {
          await setPhoto(file);
          dialog.toast('Foto salva');
        }
      }
    } catch (e) {
      await dialog.notice({
        title: 'Não deu para salvar',
        message: e instanceof ApiError ? (e.fields.photo ?? e.message) : 'Tente de novo em instantes.',
        tone: 'error',
      });
    } finally {
      setSaving(false);
    }
  }

  async function sair() {
    if (await confirm('Sair', 'Quer sair do app neste aparelho?', 'Sair', { undo: true, text: 'É só entrar de novo com o seu e-mail e a senha.' })) {
      await logout();
    }
  }

  return (
    <Screen title="Perfil" tab>
      {me?.current ? (
        <Card style={styles.profile}>
          <Pressable onPress={changePhoto} disabled={saving} accessibilityRole="button" accessibilityLabel="Trocar a foto do perfil" style={({ pressed }) => (pressed || saving ? { opacity: 0.7 } : null)}>
            <Avatar name={me.current.name ?? me.user.name} size={64} />
            <View style={styles.camera}>
              <Ionicons name="camera" size={13} color="#ffffff" />
            </View>
          </Pressable>
          <View style={{ flex: 1 }}>
            <Text style={styles.name}>{me.current.name ?? me.user.name}</Text>
            <Body muted>
              {me.current.unit_label} · {me.current.condominium_name}
            </Body>
          </View>
        </Card>
      ) : null}
      <MenuGroup title="Conta" items={ACCOUNT} />
      <BiometricRow />
      <View style={{ gap: space[2], marginTop: space[3] }}>
        {many ? <Button title="Trocar de unidade" variant="secondary" onPress={() => router.push('/trocar-unidade')} /> : null}
        <Button title="Sair" variant="danger" onPress={sair} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  profile: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  camera: {
    position: 'absolute',
    right: -2,
    bottom: -2,
    width: 24,
    height: 24,
    borderRadius: 12,
    backgroundColor: colors.brand,
    borderWidth: 2,
    borderColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  name: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  groupTitle: { fontFamily: fonts.semibold, fontSize: 13, letterSpacing: 0.5, textTransform: 'uppercase', color: colors.muted, marginTop: space[3], marginLeft: space[1] },
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
