import { useAuth } from '../src/auth/AuthContext';
import { useResource } from '../src/lib/useResource';
import { Body, Card, CardTitle, Empty, ErrorBox, Loading, Screen, SectionTitle } from '../src/ui/kit';

export default function PetsEVeiculos() {
  const { api, residentId } = useAuth();
  const res = useResource(() => api.petsVehicles(), [residentId]);
  return (
    <Screen refreshing={res.refreshing} onRefresh={res.reload}>
      {res.loading ? <Loading /> : null}
      {res.error ? <ErrorBox error={res.error} onRetry={res.reload} /> : null}
      {res.data ? (
        <>
          <Body muted>Algo errado? Peça a correção à administração.</Body>
          <SectionTitle>Pets</SectionTitle>
          {res.data.pets.length === 0 ? <Empty text="Nenhum pet cadastrado." /> : null}
          {res.data.pets.map((p) => (
            <Card key={`p${p.id}`}>
              <CardTitle>{p.name}</CardTitle>
              <Body muted>{[p.species, p.breed].filter(Boolean).join(' · ') || '—'}</Body>
            </Card>
          ))}
          <SectionTitle>Veículos</SectionTitle>
          {res.data.vehicles.length === 0 ? <Empty text="Nenhum veículo cadastrado." /> : null}
          {res.data.vehicles.map((v) => (
            <Card key={`v${v.id}`}>
              <CardTitle>{v.plate}</CardTitle>
              <Body muted>{[v.model, v.color].filter(Boolean).join(' · ') || '—'}</Body>
            </Card>
          ))}
        </>
      ) : null}
    </Screen>
  );
}
