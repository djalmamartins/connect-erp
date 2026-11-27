import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { Pressable, StyleSheet, TextInput, View } from 'react-native';
import { sortQueue } from '../../src/lib/tickets';
import { usePagedTickets } from '../../src/lib/usePagedTickets';
import { useAuth } from '../../src/auth/AuthContext';
import { colors, fonts, radius, space } from '../../src/theme';
import { Empty, ErrorBox, Loading, Screen } from '../../src/ui/kit';
import { LoadMore } from '../../src/ui/LoadMore';
import { TicketRow } from '../../src/ui/TicketRow';

/** Busca em todos os chamados do escopo (abertos e fechados) por assunto ou protocolo SAC/OCO. */
export default function Buscar() {
  const { api } = useAuth();
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const list = usePagedTickets((before) => (search.length >= 2 ? api.tickets('all', search, 0, before) : null), [search]);
  const tickets = list.tickets ? sortQueue(list.tickets) : [];

  return (
    <Screen title="Buscar" tab refreshing={list.refreshing} onRefresh={search ? list.reload : undefined}>
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
      {search.length < 2 ? (
        <Empty text="Digite pelo menos 2 letras do assunto ou o protocolo e toque em Buscar." />
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
  input: { flex: 1, fontFamily: fonts.regular, fontSize: 15, color: colors.text },
});
