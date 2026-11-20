import * as Linking from 'expo-linking';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { useProfile } from '../src/auth/ProfileContext';
import { brDate } from '../src/lib/privacy';
import { space } from '../src/theme';
import { CheckRow } from '../src/ui/CheckRow';
import { dialog } from '../src/ui/Dialog';
import { Body, Button, Card, Screen } from '../src/ui/kit';

/**
 * Aceite dos Termos de Uso e da Política de Privacidade (LGPD, ADR 0600). Aparece no primeiro acesso e quando a
 * versão muda; o aceite fica registrado no servidor (data, versão e canal). Novidades é outra caixa, opcional.
 */
export default function Termos() {
  const { logout } = useAuth();
  const { privacy, acceptTerms } = useProfile();
  const [terms, setTerms] = useState(false);
  const [marketing, setMarketing] = useState(!!privacy?.marketing);
  const [busy, setBusy] = useState(false);

  async function accept() {
    if (!terms) {
      void dialog.notice({ title: 'Falta o aceite', message: 'Marque que leu e aceita os Termos de Uso e a Política de Privacidade.', tone: 'info', icon: 'lock-closed-outline' });
      return;
    }
    setBusy(true);
    try {
      await acceptTerms(marketing);
    } catch (e) {
      void dialog.notice({ title: 'Não foi possível registrar', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'danger' });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title="Termos e privacidade" subtitle={privacy ? `Versão de ${brDate(privacy.terms_version)}` : undefined}>
      <Card>
        <Body>Para usar o aplicativo, leia e aceite os Termos de Uso e a Política de Privacidade.</Body>
        <View style={styles.list}>
          <Body>• Usamos seus dados para administrar o condomínio: boletos, avisos, reservas, chamados e portaria.</Body>
          <Body>• Não vendemos seus dados nem fazemos propaganda sem a sua autorização.</Body>
          <Body>• Você pode pedir acesso, correção ou exclusão dos seus dados em Perfil, Privacidade.</Body>
        </View>
        {privacy ? (
          <View style={styles.links}>
            <Button title="Ler os Termos de Uso" variant="secondary" onPress={() => Linking.openURL(privacy.terms_url)} />
            <Button title="Ler a Política de Privacidade" variant="secondary" onPress={() => Linking.openURL(privacy.privacy_url)} />
          </View>
        ) : null}
        <CheckRow label="Li e aceito os Termos de Uso e a Política de Privacidade." checked={terms} onChange={setTerms} />
        <CheckRow label="Quero receber novidades da administradora (opcional)." checked={marketing} onChange={setMarketing} />
      </Card>
      <View style={styles.actions}>
        <Button title="Aceitar e continuar" onPress={accept} busy={busy} />
        <Button title="Não aceito, sair" variant="danger" onPress={() => void logout()} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  list: { gap: space[1], marginVertical: space[3] },
  links: { gap: space[2], marginBottom: space[3] },
  actions: { gap: space[2], marginTop: space[3] },
});
