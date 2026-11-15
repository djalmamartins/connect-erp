import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';
import { ApiError } from '../../src/api/client';
import { useAuth } from '../../src/auth/AuthContext';
import { parseDate, today } from '../../src/lib/format';
import { space } from '../../src/theme';
import { Body, Button, Card, Field, Screen } from '../../src/ui/kit';
import { dialog } from '../../src/ui/Dialog';

type Kind = 'resident' | 'tenant';

/** Cadastra morador ou inquilino da unidade. O servidor confere quem pode cadastrar o quê. */
export default function NovoMorador() {
  const { api } = useAuth();
  const { tipo } = useLocalSearchParams<{ tipo?: string }>();
  // O tipo vem do botão da lista, que só aparece para quem pode cadastrar aquele tipo.
  const kind: Kind = tipo === 'tenant' ? 'tenant' : 'resident';
  const [name, setName] = useState('');
  const [document, setDocument] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [starts, setStarts] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function save() {
    const e: Record<string, string> = {};
    const startsOn = starts.trim() === '' ? today() : parseDate(starts);
    if (name.trim().split(/\s+/).length < 2) e.name = 'Informe o nome e o sobrenome.';
    if (email.trim() !== '' && !/^\S+@\S+\.\S+$/.test(email.trim())) e.email = 'E-mail inválido.';
    if (!startsOn) e.starts_on = 'Use o formato DD/MM/AAAA.';
    setErrors(e);
    if (Object.keys(e).length > 0 || !startsOn) return;
    setBusy(true);
    try {
      await api.addHouseholdMember({
        relationship: kind,
        name: name.trim(),
        document: document.trim() || undefined,
        email: email.trim() || undefined,
        phone: phone.trim() || undefined,
        starts_on: startsOn,
      });
      dialog.toast(kind === 'tenant' ? 'Inquilino cadastrado' : 'Morador cadastrado');
      router.back();
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.fields);
        void dialog.notice({ title: 'Não deu para cadastrar', message: err.message, tone: 'error' });
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title={kind === 'tenant' ? 'Novo inquilino' : 'Novo morador'} back>
      <Card>
        <View style={{ gap: space[3] }}>
          <Body muted>
            {kind === 'tenant'
              ? 'Inquilino aluga a unidade. Só o proprietário cadastra inquilino.'
              : 'Morador mora na unidade com o proprietário ou o inquilino, como cônjuge, filhos ou parentes.'}
          </Body>
          <Field label="Nome completo" value={name} onChangeText={setName} autoCapitalize="words" error={errors.name} />
          <Field label="CPF (opcional)" value={document} onChangeText={setDocument} keyboardType="number-pad" error={errors.document} />
          <Field label="E-mail (opcional)" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoCorrect={false} error={errors.email} />
          <Field label="Celular (opcional)" value={phone} onChangeText={setPhone} keyboardType="phone-pad" error={errors.phone} />
          <Field
            label="Mora desde"
            value={starts}
            onChangeText={setStarts}
            placeholder="DD/MM/AAAA (em branco = hoje)"
            keyboardType="numbers-and-punctuation"
            error={errors.starts_on}
          />
          <Body muted>Os dados ficam com a administração do condomínio e não aparecem para os vizinhos.</Body>
          <Button title="Cadastrar" onPress={save} busy={busy} />
        </View>
      </Card>
    </Screen>
  );
}
