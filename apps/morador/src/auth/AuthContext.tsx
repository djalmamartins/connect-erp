import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Platform } from 'react-native';
import { createClient, type ApiClient } from '../api/client';
import { residentApi, type LoginResult, type Me, type ResidentApi } from '../api/resident';
import { apiBaseUrl } from '../config';
import { tokenStorage } from './storage';

type Status = 'loading' | 'signedOut' | 'signedIn';

type AuthValue = {
  status: Status;
  me: Me | null;
  api: ResidentApi;
  client: ApiClient;
  residentId: number | null;
  login(login: string, password: string): Promise<LoginResult>;
  verifyTwoFactor(challenge: string, code: string): Promise<void>;
  logout(): Promise<void>;
  selectResident(id: number): Promise<void>;
  refreshMe(): Promise<void>;
};

const AuthContext = createContext<AuthValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('loading');
  const [me, setMe] = useState<Me | null>(null);
  const [residentId, setResidentId] = useState<number | null>(null);
  const tokenRef = useRef<string | null>(null);
  const residentRef = useRef<number | null>(null);

  const signOutLocally = useCallback(async () => {
    tokenRef.current = null;
    residentRef.current = null;
    setMe(null);
    setResidentId(null);
    setStatus('signedOut');
    await tokenStorage.setToken(null);
    await tokenStorage.setResidentId(null);
  }, []);

  const client = useMemo(
    () =>
      createClient({
        baseUrl: apiBaseUrl(),
        getToken: () => tokenRef.current,
        getResidentId: () => residentRef.current,
        onUnauthenticated: () => {
          if (tokenRef.current !== null) {
            void signOutLocally();
          }
        },
      }),
    [signOutLocally],
  );
  const api = useMemo(() => residentApi(client), [client]);

  const loadMe = useCallback(async () => {
    const data = await api.me();
    const ids = data.links.map((link) => link.resident_id);
    if (residentRef.current === null || !ids.includes(residentRef.current)) {
      residentRef.current = data.current?.resident_id ?? ids[0] ?? null;
      setResidentId(residentRef.current);
      await tokenStorage.setResidentId(residentRef.current);
    }
    setMe(data);
  }, [api]);

  const startSession = useCallback(
    async (token: string) => {
      if (!token) {
        throw new Error('Resposta sem token.');
      }
      tokenRef.current = token;
      await tokenStorage.setToken(token);
      await loadMe();
      setStatus('signedIn');
    },
    [loadMe],
  );

  useEffect(() => {
    let alive = true;
    (async () => {
      const token = await tokenStorage.getToken();
      if (!token) {
        if (alive) setStatus('signedOut');
        return;
      }
      tokenRef.current = token;
      residentRef.current = await tokenStorage.getResidentId();
      setResidentId(residentRef.current);
      try {
        await loadMe();
        if (alive) setStatus('signedIn');
      } catch {
        // Sem rede: mantém o token e mostra o login só se a sessão foi recusada (401 já limpou).
        if (alive) setStatus(tokenRef.current ? 'signedIn' : 'signedOut');
      }
    })();
    return () => {
      alive = false;
    };
  }, [loadMe]);

  const value = useMemo<AuthValue>(
    () => ({
      status,
      me,
      api,
      client,
      residentId,
      async login(login, password) {
        const result = await api.login(login, password, `App Connect Morador (${Platform.OS})`);
        if (result.kind === 'ok') {
          await startSession(result.session.token);
        }
        return result;
      },
      async verifyTwoFactor(challenge, code) {
        const session = await api.verifyTwoFactor(challenge, code);
        await startSession(session.token);
      },
      async logout() {
        try {
          await api.logout();
        } catch {
          // Mesmo sem rede o aparelho esquece o token; o servidor expira a sessão sozinho.
        }
        await signOutLocally();
      },
      async selectResident(id) {
        residentRef.current = id;
        setResidentId(id);
        await tokenStorage.setResidentId(id);
        await loadMe();
      },
      refreshMe: loadMe,
    }),
    [status, me, api, client, residentId, startSession, signOutLocally, loadMe],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext);
  if (!value) {
    throw new Error('useAuth fora do AuthProvider');
  }
  return value;
}
