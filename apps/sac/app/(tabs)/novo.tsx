import Ionicons from '@expo/vector-icons/Ionicons';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { Pressable, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { NewTicket, TicketPriority } from '../../src/api/sac';
import { useAuth } from '../../src/auth/AuthContext';
import { CHANNEL_LABELS, NEW_CHANNELS, newTicketProblem, PRIORITY_LABELS } from '../../src/lib/tickets';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { dialog } from '../../src/ui/Dialog';
import { Button, Card, Choice, ErrorBox, Field, Loading, Screen } from '../../src/ui/kit';

type Channel = NewTicket['channel'];
const PRIORITIES: TicketPriority[] = ['low', 'medium', 'high', 'urgent'];

/**
 * Novo chamado: a equipe registra o pedido de quem ligou, mandou WhatsApp para o número da Connect
 * ou veio ao escritório (POST /api/v1/sac/tickets, ADR 0661). O protocolo SAC sai ao salvar.
 */
export default function Novo() {
  const { api, me } = useAuth();
  const options = useResource(() => api.options(), []);
  const [condominium, setCondominium] = useState<{ id: number; name: string } | null>(null);
  const [unit, setUnit] = useState<{ id: number; label: string } | null>(null);
  const units = useResource(() => (condominium ? api.units(condominium.id) : Promise.resolve([])), [condominium?.id]);
  const [channel, setChannel] = useState<Channel>('telefone');
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [priority, setPriority] = useState<TicketPriority>('medium');
  const [assignMe, setAssignMe] = useState(true);
  const [busy, setBusy] = useState(false);

  const reset = () => {
    setUnit(null);
    setName('');
    setPhone('');
    setEmail('');
    setSubject('');
    setMessage('');
    setPriority('medium');
  };

  async function save() {
    const problem = newTicketProblem({ condominium_id: condominium?.id ?? null, requester_name: name, requester_phone: phone, requester_email: email, subject, message });
    if (problem) {
      await dialog.notice({ title: 'Falta um dado', message: problem, tone: 'info' });
      return;
    }
    setBusy(true);
    try {
      const opened = await api.open({
        condominium_id: condominium!.id,
        unit_id: unit?.id ?? null,
        requester_name: name.trim(),
        requester_phone: phone.trim(),
        requester_email: email.trim(),
        channel,
        subject: subject.trim(),
        message: message.trim(),
        priority,
        assign_me: assignMe,
      });
      reset();
      dialog.toast(`Chamado ${opened.protocol} aberto`);
      router.push(`/chamados/${opened.id}`);
    } catch (e) {
      await dialog.notice({ title: 'Não deu para abrir', message: e instanceof ApiError ? e.message : 'Tente de novo em instantes.', tone: 'error' });
    } finally {
      setBusy(false);
    }
  }

  if (options.loading) {
    return (
      <Screen title="Novo chamado" tab>
        <Loading />
      </Screen>
    );
  }
  if (options.error) {
    return (
      <Screen title="Novo chamado" tab>
        <ErrorBox error={options.error} onRetry={options.reload} />
      </Screen>
    );
  }
  if (me && !me.can_attend_sac) {
    return (
      <Screen title="Novo chamado" tab>
        <Card>
          <Text style={styles.note}>Abrir chamado pelo app é com a equipe de atendimento. Peça a permissão de atendimento ao administrador.</Text>
        </Card>
      </Screen>
    );
  }

  return (
    <Screen title="Novo chamado" tab>
      <Picker
        label="Condomínio"
        placeholder="Escolha o condomínio"
        items={(options.data?.condominiums ?? []).map((c) => ({ id: c.id, label: c.name }))}
        value={condominium ? { id: condominium.id, label: condominium.name } : null}
        onChange={(item) => {
          setCondominium(item ? { id: item.id, name: item.label } : null);
          setUnit(null);
        }}
      />
      {condominium ? (
        <Picker
          label="Unidade (se for de uma unidade)"
          placeholder={units.loading ? 'Carregando unidades…' : 'Sem unidade (área comum)'}
          items={(units.data ?? []).map((u) => ({ id: u.id, label: u.label }))}
          value={unit}
          onChange={setUnit}
          clearLabel="Sem unidade (área comum)"
        />
      ) : null}

      <Choice label="Como chegou" options={NEW_CHANNELS.map((c) => ({ value: c as Channel, label: CHANNEL_LABELS[c] }))} value={channel} onChange={setChannel} />

      <Field label="Quem pediu" value={name} onChangeText={setName} placeholder="Nome" autoCapitalize="words" />
      <Field label="Telefone" value={phone} onChangeText={setPhone} mask="phone" hint="Telefone ou e-mail: um dos dois." />
      <Field label="E-mail (opcional)" value={email} onChangeText={setEmail} keyboardType="email-address" autoCapitalize="none" autoCorrect={false} placeholder="nome@exemplo.com" />
      <Field label="Assunto" value={subject} onChangeText={setSubject} placeholder="Ex.: Vazamento no teto da garagem" maxLength={180} />
      <Field label="Descrição" value={message} onChangeText={setMessage} multiline placeholder="O que a pessoa contou" />
      <Choice label="Prioridade" options={PRIORITIES.map((p) => ({ value: p, label: PRIORITY_LABELS[p] }))} value={priority} onChange={setPriority} />

      <View style={styles.assign}>
        <Text style={styles.assignText}>Fica comigo</Text>
        <Switch value={assignMe} onValueChange={setAssignMe} trackColor={{ true: colors.accent, false: colors.border }} thumbColor={colors.surface} accessibilityLabel="Fica comigo" />
      </View>

      <View style={styles.proto}>
        <Ionicons name="pricetag-outline" size={16} color="#6b5510" />
        <Text style={styles.protoText}>O protocolo SAC sai ao salvar e vai no aviso para quem pediu, quando tiver e-mail.</Text>
      </View>
      <Button title="Abrir chamado" onPress={() => void save()} busy={busy} />
    </Screen>
  );
}

type Item = { id: number; label: string };

/** Lista com busca, para escolher condomínio e unidade sem abrir uma janela comprida. */
function Picker({ label, placeholder, items, value, onChange, clearLabel }: { label: string; placeholder: string; items: Item[]; value: Item | null; onChange: (item: Item | null) => void; clearLabel?: string }) {
  const [open, setOpen] = useState(false);
  const [filter, setFilter] = useState('');
  const shown = useMemo(() => {
    const f = filter.trim().toLowerCase();
    return (f ? items.filter((i) => i.label.toLowerCase().includes(f)) : items).slice(0, 8);
  }, [items, filter]);

  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <Pressable onPress={() => setOpen((o) => !o)} accessibilityRole="button" accessibilityLabel={`${label}: ${value?.label ?? placeholder}`} style={styles.select}>
        <Text style={[styles.selectText, !value && { color: colors.faint }]} numberOfLines={1}>
          {value?.label ?? placeholder}
        </Text>
        <Ionicons name={open ? 'chevron-up' : 'chevron-down'} size={18} color={colors.accent} />
      </Pressable>
      {open ? (
        <View style={styles.list}>
          {items.length > 8 ? (
            <TextInput value={filter} onChangeText={setFilter} placeholder="Buscar" placeholderTextColor={colors.faint} style={styles.filter} accessibilityLabel={`Buscar ${label.toLowerCase()}`} />
          ) : null}
          {clearLabel ? (
            <Pressable onPress={() => { onChange(null); setOpen(false); }} style={styles.option} accessibilityRole="button">
              <Text style={[styles.optionText, { color: colors.muted }]}>{clearLabel}</Text>
            </Pressable>
          ) : null}
          {shown.map((i) => (
            <Pressable key={i.id} onPress={() => { onChange(i); setOpen(false); setFilter(''); }} style={styles.option} accessibilityRole="button" accessibilityState={{ selected: value?.id === i.id }}>
              <Text style={[styles.optionText, value?.id === i.id && { color: colors.accent, fontFamily: fonts.semibold }]}>{i.label}</Text>
            </Pressable>
          ))}
          {shown.length === 0 ? <Text style={styles.empty}>Nada encontrado.</Text> : null}
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  note: { fontFamily: fonts.regular, fontSize: 15, color: colors.muted },
  field: { gap: 6 },
  label: { fontFamily: fonts.semibold, fontSize: 14, color: colors.info },
  select: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space[2], height: 46, borderRadius: radius.md, borderWidth: 1, borderColor: '#dfe2df', backgroundColor: colors.surface, paddingHorizontal: space[3] },
  selectText: { flex: 1, fontFamily: fonts.regular, fontSize: 15, color: colors.text },
  list: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, overflow: 'hidden' },
  filter: { height: 42, paddingHorizontal: space[3], fontFamily: fonts.regular, fontSize: 15, color: colors.text, borderBottomWidth: 1, borderBottomColor: colors.border },
  option: { paddingHorizontal: space[3], paddingVertical: 11, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  optionText: { fontFamily: fonts.regular, fontSize: 15, color: colors.text },
  empty: { fontFamily: fonts.regular, fontSize: 14, color: colors.faint, padding: space[3] },
  assign: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  assignText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  proto: { flexDirection: 'row', alignItems: 'center', gap: space[2], backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.accentLine, borderRadius: radius.md, padding: space[3] },
  protoText: { flex: 1, fontFamily: fonts.regular, fontSize: 13, color: '#6b5510' },
});
