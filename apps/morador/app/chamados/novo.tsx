import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { Priority, TicketKind } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { parseDate, today } from '../../src/lib/format';
import { space } from '../../src/theme';
import { Body, Button, Card, Choice, Field, Screen } from '../../src/ui/kit';
import { dialog } from '../../src/ui/Dialog';

const PRIORITIES: { value: Priority; label: string }[] = [
  { value: 'baixa', label: 'Baixa' },
  { value: 'media', label: 'Média' },
  { value: 'alta', label: 'Alta' },
];

const TEXT: Record<TicketKind, { title: string; intro: string; subject: string; description: string; sent: string }> = {
  chamado: {
    title: 'Abrir chamado',
    intro: 'Peça ajuda ou tire uma dúvida com a administração. A resposta chega aqui, na conversa do chamado.',
    subject: 'Ex.: Dúvida sobre o boleto de novembro',
    description: 'Conte com detalhes o que você precisa.',
    sent: 'Chamado aberto',
  },
  ocorrencia: {
    title: 'Registrar ocorrência',
    intro: 'Avise sobre um problema no condomínio, como barulho, vazamento ou algo quebrado. A ocorrência vira um chamado e você acompanha por aqui.',
    subject: 'Ex.: Vazamento na garagem',
    description: 'O que aconteceu, onde e a que horas.',
    sent: 'Ocorrência registrada',
  },
};

/** Abre um chamado ou registra uma ocorrência (as duas viram chamado do Help Desk, ADR 0305). */
export default function NovoChamado() {
  const { api } = useAuth();
  const { tipo } = useLocalSearchParams<{ tipo?: string }>();
  const kind: TicketKind = tipo === 'ocorrencia' ? 'ocorrencia' : 'chamado';
  const t = TEXT[kind];
  const [subject, setSubject] = useState('');
  const [description, setDescription] = useState('');
  const [priority, setPriority] = useState<Priority>('media');
  const [when, setWhen] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function send() {
    const e: Record<string, string> = {};
    const occurredOn = kind === 'ocorrencia' && when.trim() !== '' ? parseDate(when) : today();
    if (subject.trim().length < 3) e.subject = 'Escreva um assunto curto.';
    if (description.trim().length < 5) e.description = kind === 'ocorrencia' ? 'Conte o que aconteceu.' : 'Conte o que você precisa.';
    if (!occurredOn) e.occurred_on = 'Use o formato DD/MM/AAAA.';
    setErrors(e);
    if (Object.keys(e).length > 0 || !occurredOn) return;
    setBusy(true);
    try {
      const ticket = await api.openTicket({ kind, subject: subject.trim(), description: description.trim(), priority, occurred_on: occurredOn });
      await dialog.notice({
        title: t.sent,
        message: `${ticket?.number ? `Protocolo ${ticket.number}. ` : ''}A administração responde na conversa do chamado.`,
        tone: 'success',
      });
      if (ticket?.id) {
        router.replace(`/chamados/${ticket.id}`);
      } else {
        router.back();
      }
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.fields);
        void dialog.notice({ title: 'Não deu para enviar', message: err.message, tone: 'error' });
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title={t.title} back>
      <Card>
        <View style={{ gap: space[3] }}>
        <Body muted>{t.intro}</Body>
        <Field label="Assunto" value={subject} onChangeText={setSubject} placeholder={t.subject} error={errors.subject} maxLength={150} />
        <Field
          label={kind === 'ocorrencia' ? 'O que aconteceu' : 'Descrição'}
          value={description}
          onChangeText={setDescription}
          placeholder={t.description}
          multiline
          error={errors.description}
        />
        {kind === 'ocorrencia' ? (
          <Field
            label="Quando aconteceu"
            value={when}
            onChangeText={setWhen}
            mask="date"
            placeholder="DD/MM/AAAA (em branco = hoje)"
            error={errors.occurred_on}
          />
        ) : null}
        <Choice label="Urgência" value={priority} options={PRIORITIES} onChange={setPriority} />
        <Button title={kind === 'ocorrencia' ? 'Registrar ocorrência' : 'Abrir chamado'} onPress={send} busy={busy} />
        </View>
      </Card>
    </Screen>
  );
}
