import Ionicons from "@expo/vector-icons/Ionicons";
import { router, useFocusEffect } from "expo-router";
import { useCallback, useRef, useState } from "react";
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { ApiError } from "../../src/api/client";
import type { Ticket, TicketKind } from "../../src/api/resident";
import { useAuth } from "../../src/auth/AuthContext";
import { hasMore, mergeTickets, ticketKind, when } from "../../src/lib/tickets";
import { colors, fonts, radius, space } from "../../src/theme";
import {
  Choice,
  Empty,
  ErrorBox,
  IconRow,
  Loading,
  Screen,
  StatusBadge,
} from "../../src/ui/kit";

const FILTERS: { value: "todos" | TicketKind; label: string }[] = [
  { value: "todos", label: "Todos" },
  { value: "chamado", label: "Chamados" },
  { value: "ocorrencia", label: "Ocorrências" },
];

/**
 * Chamados do morador (Help Desk, ADR 0305): os que ele abriu, inclusive as ocorrências.
 * Rolagem infinita de 20 em 20, como o histórico de boletos.
 */
export default function Chamados() {
  const { api, residentId } = useAuth();
  const [items, setItems] = useState<Ticket[]>([]);
  const [page, setPage] = useState(1);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [more, setMore] = useState(false);
  const [filter, setFilter] = useState<"todos" | TicketKind>("todos");
  const kind = filter === "todos" ? undefined : filter;
  const busy = useRef(false);

  const first = useCallback(
    async (isRefresh: boolean) => {
      if (isRefresh) setRefreshing(true);
      try {
        const data = await api.tickets(1, kind);
        setItems(data ?? []);
        setPage(1);
        setDone(!hasMore(data ?? []));
        setError(null);
      } catch (e) {
        setError(
          e instanceof ApiError
            ? e
            : new ApiError(0, "unknown", "Não foi possível carregar."),
        );
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [api, kind],
  );

  // Recarrega ao voltar de um chamado (a situação pode ter mudado) e ao trocar de unidade.
  useFocusEffect(
    useCallback(() => {
      void first(false);
    }, [first, residentId]),
  );

  async function loadMore() {
    if (busy.current || done || loading || error) return;
    busy.current = true;
    setMore(true);
    try {
      const next = await api.tickets(page + 1, kind);
      setItems((cur) => mergeTickets(cur, next ?? []));
      setPage(page + 1);
      setDone(!hasMore(next ?? []));
    } catch {
      setDone(true);
    } finally {
      busy.current = false;
      setMore(false);
    }
  }

  const actions = (
    <View style={styles.actions}>
      <NewButton
        icon="chatbubbles"
        label="Novo chamado"
        onPress={() => router.push("/chamados/novo?tipo=chamado")}
      />
      <NewButton
        icon="alert-circle"
        label="Nova ocorrência"
        onPress={() => router.push("/chamados/novo?tipo=ocorrencia")}
      />
    </View>
  );

  return (
    <Screen
      title="Meus registros"
      subtitle="Chamados e ocorrências"
      back
      refreshing={refreshing}
      onRefresh={() => first(true)}
      onEndReached={loadMore}
    >
      {loading ? (
        <Loading />
      ) : error ? (
        <ErrorBox error={error} onRetry={() => first(false)} />
      ) : (
        <>
          {actions}
          <Choice
            label="Mostrar"
            options={FILTERS}
            value={filter}
            onChange={(f) => {
              setLoading(true);
              setFilter(f);
            }}
          />
          {items.length === 0 ? (
            <Empty
              text={
                filter === "todos"
                  ? "Você ainda não abriu nenhum chamado ou ocorrência."
                  : filter === "chamado"
                    ? "Nenhum chamado por aqui."
                    : "Nenhuma ocorrência por aqui."
              }
            />
          ) : null}
          {items.map((t) => {
            const oc = ticketKind(t) === "ocorrencia";
            return (
              <IconRow
                key={t.id}
                icon={oc ? "alert-circle" : "chatbubbles"}
                tint={oc ? colors.danger : "#c2611f"}
                bg={oc ? colors.dangerSoft : "#fdf0e6"}
                title={t.subject}
                subtitle={`Nº ${t.number} · ${when(t.last_message_at ?? t.updated_at)}`}
                onPress={() => router.push(`/chamados/${t.id}`)}
                accessibilityLabel={`${t.subject}, ${t.status_label}`}
              >
                <View style={styles.badge}>
                  <StatusBadge kind="ticket" status={t.status} />
                </View>
              </IconRow>
            );
          })}
          {more ? (
            <ActivityIndicator
              color={colors.brand}
              style={{ marginVertical: space[4] }}
            />
          ) : null}
        </>
      )}
    </Screen>
  );
}

function NewButton({
  icon,
  label,
  onPress,
}: {
  icon: "chatbubbles" | "alert-circle";
  label: string;
  onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      style={({ pressed }) => [styles.new, pressed && { opacity: 0.8 }]}
    >
      <Ionicons name={icon} size={20} color={colors.onBrand} />
      <Text style={styles.newText}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  badge: {
    alignSelf: "flex-start",
    marginTop: space[2],
    marginLeft: 42 + space[3],
  },
  actions: { flexDirection: "row", gap: space[2] },
  new: {
    flex: 1,
    minHeight: 48,
    borderRadius: radius.pill,
    backgroundColor: colors.brand,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: space[2],
    paddingHorizontal: space[3],
  },
  newText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.onBrand },
});
