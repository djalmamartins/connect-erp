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
            <Card key={`p${p.name}`}>
              <CardTitle>{p.name}</CardTitle>
              <Body muted>{[p.species, p.breed, p.size, p.vaccinated ? 'vacinado' : null].filter(Boolean).join(' · ') || '—'}</Body>
            </Card>
          ))}
          <SectionTitle>Veículos</SectionTitle>
          {res.data.vehicles.length === 0 ? <Empty text="Nenhum veículo cadastrado." /> : null}
          {res.data.vehicles.map((v) => (
            <Card key={`v${v.plate}`}>
              <CardTitle>{v.plate}</CardTitle>
              <Body muted>{[v.kind, v.model, v.color, v.parking_spot ? `vaga ${v.parking_spot}` : null].filter(Boolean).join(' · ') || '—'}</Body>
            </Card>
          ))}
        </>
      ) : null}
    </Screen>
  );
}
