import * as Linking from 'expo-linking';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { useProfile } from '../src/auth/ProfileContext';
import { brDate, requestOptions } from '../src/lib/privacy';
import { space } from '../src/theme';
import { dialog } from '../src/ui/Dialog';
import { Body, Button, Card, CardTitle, Choice, Field, Screen } from '../src/ui/kit';

/**
 * Privacidade e meus dados (LGPD, ADR 0604): o que foi aceito, novidades (dá para retirar) e pedidos do titular
 * (art. 18), que viram chamado LGPD para a administradora, com resposta em até 15 dias.
 */
export default function Privacidade() {
  const { api } = useAuth();
  const { privacy, setMarketing } = useProfile();
  const [type, setType] = useState<string | null>(null);
  const [details, setDetails] = useState('');
  const [busy, setBusy] = useState<null | 'marketing' | 'request'>(null);

  async function toggleMarketing() {
    if (!privacy) return;
    setBusy('marketing');
    try {
      await setMarketing(!privacy.marketing);
      dialog.toast(privacy.marketing ? 'Você não recebe mais novidades.' : 'Você vai receber novidades.');
    } catch (e) {
      void dialog.notice({ title: 'Não foi possível salvar', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'danger' });
    } finally {
      setBusy(null);
    }
  }

  async function send() {
    if (!type) {
      void dialog.notice({ title: 'Escolha o pedido', message: 'Diga o que você quer fazer com os seus dados.', tone: 'info' });
      return;
    }
    setBusy('request');
    try {
      const r = await api.privacyRequest(type, details);
      setType(null);
      setDetails('');
      void dialog.notice({ title: 'Pedido enviado', message: `Protocolo ${r.protocol}. Respondemos até ${brDate(r.answer_until)}.`, tone: 'info', icon: 'checkmark-circle-outline' });
    } catch (e) {
      void dialog.notice({ title: 'Não foi possível enviar', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'danger' });
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen title="Privacidade e meus dados" back>
      <Card>
        <CardTitle>Termos e Política</CardTitle>
        <Body muted>{privacy?.accepted_at ? `Aceitos em ${brDate(privacy.accepted_at)}.` : 'Aceite pendente.'}</Body>
        {privacy ? (
          <View style={styles.gap}>
            <Button title="Ler os Termos de Uso" variant="secondary" onPress={() => Linking.openURL(privacy.terms_url)} />
            <Button title="Ler a Política de Privacidade" variant="secondary" onPress={() => Linking.openURL(privacy.privacy_url)} />
          </View>
        ) : null}
      </Card>
      <Card>
        <CardTitle>Novidades</CardTitle>
        <Body muted>
          {privacy?.marketing
            ? 'Você recebe novidades da administradora.'
            : 'Você não recebe novidades. Avisos do condomínio (boletos, assembleias, encomendas) continuam chegando.'}
        </Body>
        <View style={styles.gap}>
          <Button
            title={privacy?.marketing ? 'Não quero mais receber novidades' : 'Quero receber novidades'}
            variant="secondary"
            onPress={toggleMarketing}
            busy={busy === 'marketing'}
            disabled={!privacy}
          />
        </View>
      </Card>
      <Card>
        <CardTitle>Pedir sobre meus dados</CardTitle>
        <Body muted>
          O pedido vira um chamado para a administradora, que responde em até {privacy?.answer_days ?? 15} dias. Dados que a lei manda
          guardar (por exemplo, a contabilidade do condomínio) não podem ser apagados; nesse caso, explicamos o motivo.
        </Body>
        <Choice label="O que você quer" options={requestOptions(privacy)} value={type} onChange={setType} />
        <Field label="Detalhes (opcional)" value={details} onChangeText={setDetails} multiline maxLength={2000} />
        <Button title="Enviar pedido" onPress={send} busy={busy === 'request'} />
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  gap: { gap: space[2], marginTop: space[2] },
});
