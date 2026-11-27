import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import type { TicketStatus } from '../../src/api/sac';
import { searchReady, sortQueue, STATUS_FILTERS, STATUS_LABELS } from '../../src/lib/tickets';
import { usePagedTickets } from '../../src/lib/usePagedTickets';
import { useAuth } from '../../src/auth/AuthContext';
import { colors, fonts, radius, space } from '../../src/theme';
import { Empty, ErrorBox, Loading, Screen } from '../../src/ui/kit';
import { LoadMore } from '../../src/ui/LoadMore';
import { TicketRow } from '../../src/ui/TicketRow';

/**
 * Busca em todos os chamados do escopo (abertos e fechados) por assunto ou protocolo SAC/OCO.
 * A situação filtra a busca; escolhida sem texto, lista todos daquela situação.
 */
export default function Buscar() {
  const { api } = useAuth();
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<TicketStatus | ''>('');
  const ready = searchReady(search, status);
  const list = usePagedTickets((before) => (ready ? api.tickets('all', search, 0, before, status) : null), [search, status]);
  const tickets = list.tickets ? sortQueue(list.tickets) : [];

  return (
    <Screen title="Buscar" tab refreshing={list.refreshing} onRefresh={ready ? list.reload : undefined}>
      <View style={styles.search}>
        <Ionicons name="search" size={18} color={colors.faint} />
        <TextInput
          value={q}
          onChangeText={setQ}
          onSubmitEditing={() => setSearch(q.trim())}
          placeholder="Assunto ou protocolo (SAC, OCO)"
          placeholderTextColor={colors.faint}
          returnKeyType="search"
          style={styles.input}
          accessibilityLabel="Buscar chamado"
          autoFocus
        />
        {q ? (
          <Pressable onPress={() => { setQ(''); setSearch(''); }} accessibilityLabel="Limpar busca" hitSlop={8}>
            <Ionicons name="close-circle" size={18} color={colors.faint} />
          </Pressable>
        ) : null}
      </View>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips} accessibilityLabel="Filtrar por situação">
        {([''] as (TicketStatus | '')[]).concat(STATUS_FILTERS).map((value) => {
          const on = value === status;
          return (
            <Pressable key={value || 'all'} onPress={() => setStatus(value)} accessibilityRole="button" accessibilityState={{ selected: on }} style={[styles.chip, on && styles.chipOn]}>
              <Text style={[styles.chipText, on && styles.chipTextOn]}>{value ? STATUS_LABELS[value] : 'Todas as situações'}</Text>
            </Pressable>
          );
        })}
      </ScrollView>
      {!ready ? (
        <Empty text="Digite pelo menos 2 letras do assunto ou o protocolo e toque em Buscar, ou escolha uma situação." />
      ) : list.loading ? (
        <Loading />
      ) : list.error ? (
        <ErrorBox error={list.error} onRetry={list.reload} />
      ) : tickets.length === 0 ? (
        <Empty text="Nenhum chamado encontrado." />
      ) : (
        <>
          {tickets.map((t) => (
            <TicketRow key={t.id} ticket={t} />
          ))}
          <LoadMore hasMore={list.hasMore} busy={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  search: { flexDirection: 'row', alignItems: 'center', gap: space[2], backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, paddingHorizontal: space[3], height: 46 },
  chips: { gap: space[2], paddingRight: space[2] },
  chip: { height: 32, paddingHorizontal: space[3], borderRadius: 16, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center' },
  chipOn: { backgroundColor: colors.accentSoft, borderColor: colors.accent },
  chipText: { fontFamily: fonts.medium, fontSize: 13, color: colors.muted },
  chipTextOn: { fontFamily: fonts.semibold, color: colors.text },
  input: { flex: 1, fontFamily: fonts.regular, fontSize: 15, color: colors.text },
});
