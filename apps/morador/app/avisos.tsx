import { useEffect } from 'react';
import { useAuth } from '../src/auth/AuthContext';
import { tokenStorage } from '../src/auth/storage';
import { stamp } from '../src/lib/notifications';
import { useResource } from '../src/lib/useResource';
import { Empty, ErrorBox, Loading, Screen } from '../src/ui/kit';
import { NotificationRow } from '../src/ui/Notifications';

/** Notificações do sistema (sininho): contas vencidas, encomendas, visitas, comunicados, reservas. */
export default function Avisos() {
  const { api, residentId } = useAuth();
  const list = useResource(() => api.notifications(), [residentId]);

  useEffect(() => {
    if (list.data && residentId !== null) void tokenStorage.setNotificationsSeen(residentId, stamp());
  }, [list.data, residentId]);

  return (
    <Screen title="Notificações" back refreshing={list.refreshing} onRefresh={list.reload}>
      {list.loading ? <Loading /> : null}
      {list.error ? <ErrorBox error={list.error} onRetry={list.reload} /> : null}
      {list.data && list.data.length === 0 ? <Empty text="Nenhuma notificação por enquanto." /> : null}
      {(list.data ?? []).map((n, i) => (
        <NotificationRow key={`${n.kind}${i}${n.at}`} item={n} />
      ))}
    </Screen>
  );
}
