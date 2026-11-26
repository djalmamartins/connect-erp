import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';
import type { Person } from '../api/team';

/**
 * De quem é o dia mostrado. Padrão: a própria pessoa. Quem tem "ver a equipe" (erp.myday.team) pode escolher
 * outra pessoa; o servidor confere a permissão de novo em cada pedido.
 */
type Value = { person: Person | null; setPerson(p: Person | null): void };

const PersonContext = createContext<Value>({ person: null, setPerson: () => undefined });

export function PersonProvider({ children }: { children: ReactNode }) {
  const [person, setPerson] = useState<Person | null>(null);
  const value = useMemo(() => ({ person, setPerson }), [person]);
  return <PersonContext.Provider value={value}>{children}</PersonContext.Provider>;
}

export function usePerson(): Value {
  return useContext(PersonContext);
}
