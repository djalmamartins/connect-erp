import Ionicons from '@expo/vector-icons/Ionicons';
import { StatusBar } from 'expo-status-bar';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useAuth } from '../auth/AuthContext';
import { confirm } from '../lib/confirm';
import { colors, fonts, radius, space } from '../theme';
import { Body, Button } from './kit';

/**
 * Tela de entrada rápida: o app já tem a sessão do aparelho, falta só o Face ID / digital.
 * Pede sozinha ao abrir; se a pessoa cancelar, fica o botão para tentar de novo ou entrar com a senha.
 */
export function LockScreen() {
  const { biometric, unlock, logout, lastLogin } = useAuth();
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);
  const asked = useRef(false);

  const tryUnlock = useCallback(async () => {
    setBusy(true);
    const ok = await unlock();
    setFailed(!ok);
    setBusy(false);
  }, [unlock]);

  useEffect(() => {
    if (!asked.current) {
      asked.current = true;
      void tryUnlock();
    }
  }, [tryUnlock]);

  async function usePassword() {
    if (await confirm('Entrar com e-mail e senha', 'O app vai sair deste aparelho e pedir o e-mail e a senha de novo.', 'Continuar')) {
      await logout();
    }
  }

  const icon = biometric.label === 'Face ID' || biometric.label === 'reconhecimento facial' ? 'scan-outline' : 'finger-print';
  return (
    <SafeAreaView style={styles.page}>
      <StatusBar style="light" />
      <View style={styles.body}>
        <Image source={require('../../assets/logo.png')} style={styles.logo} resizeMode="contain" accessibilityLabel="Connect Condomínios" />
        <View style={styles.card}>
          <View style={styles.badge}>
            <Ionicons name={icon} size={34} color={colors.brand} />
          </View>
          <Text style={styles.title}>Entrar com {biometric.label}</Text>
          {lastLogin ? <Body muted style={styles.center}>{lastLogin}</Body> : null}
          {failed ? <Body muted style={styles.center}>Não deu certo. Toque no botão para tentar de novo.</Body> : null}
          <Button title={`Usar ${biometric.label}`} onPress={tryUnlock} busy={busy} />
          <Button title="Entrar com e-mail e senha" variant="secondary" onPress={usePassword} />
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.sidebar },
  body: { flex: 1, justifyContent: 'center', padding: space[5], gap: space[6] },
  logo: { width: '80%', maxWidth: 320, height: 96, alignSelf: 'center' },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, padding: space[5], gap: space[4], alignItems: 'stretch' },
  badge: { alignSelf: 'center', width: 64, height: 64, borderRadius: 32, backgroundColor: colors.brandSoft, alignItems: 'center', justifyContent: 'center' },
  title: { fontFamily: fonts.bold, fontSize: 20, color: colors.text, textAlign: 'center' },
  center: { textAlign: 'center' },
});
