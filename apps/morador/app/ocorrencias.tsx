import { Redirect } from 'expo-router';

/** Ocorrência virou chamado (ADR 0305): o endereço antigo abre a lista de chamados. */
export default function Ocorrencias() {
  return <Redirect href="/chamados" />;
}
