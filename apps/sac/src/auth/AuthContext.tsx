import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Platform } from 'react-native';
import { createClient } from '../api/client';
import { sacApi, type LoginResult, type Me, type SacApi } from '../api/sac';
import { apiBaseUrl } from '../config';
import { forgetPush, registerPush } from '../lib/push';
import { storage } from './storage';

type Status = 'loading' | 'signedOut' | 'signedIn';

type AuthValue = {
  status: Status;
  me: Me | null;
  api: SacApi;
  lastLogin: string | null;
  login(login: string, password: string): Promise<LoginResult>;
  verifyTwoFactor(challenge: string, code: string): Promise<void>;
  logout(): Promise<void>;
};

const AuthContext = createContext<AuthValue | null>(null);

/** Sessão do app do SAC: token Bearer da equipe (ADR 0660), o mesmo login do ERP. */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('loading');
  const [me, setMe] = useState<Me | null>(null);
  const [lastLogin, setLastLogin] = useState<string | null>(null);
  const tokenRef = useRef<string | null>(null);

  const signOutLocally = useCallback(async () => {
    tokenRef.current = null;
    setMe(null);
    setStatus('signedOut');
    await storage.setToken(null);
  }, []);

  const api = useMemo(
    () =>
      sacApi(
        createClient({
          baseUrl: apiBaseUrl(),
          getToken: () => tokenRef.current,
          onUnauthenticated: () => {
            if (tokenRef.current !== null) void signOutLocally();
          },
        }),
      ),
    [signOutLocally],
  );

  const start = useCallback(
    async (token: string) => {
      tokenRef.current = token;
      await storage.setToken(token);
      setMe(await api.me());
      setStatus('signedIn');
    },
    [api],
  );

  useEffect(() => {
    let alive = true;
    (async () => {
      setLastLogin(await storage.getLastLogin());
      const token = await storage.getToken();
      if (!token) {
        if (alive) setStatus('signedOut');
        return;
      }
      tokenRef.current = token;
      try {
        const data = await api.me();
        if (alive) {
          setMe(data);
          setStatus('signedIn');
        }
      } catch {
        // Sem rede: entra com o token guardado; um 401 já teria limpado a sessão.
        if (alive) setStatus(tokenRef.current ? 'signedIn' : 'signedOut');
      }
    })();
    return () => {
      alive = false;
    };
  }, [api]);

  useEffect(() => {
    if (status === 'signedIn') void registerPush(api);
  }, [status, api]);

  const value = useMemo<AuthValue>(
    () => ({
      status,
      me,
      api,
      lastLogin,
      async login(login, password) {
        const result = await api.login(login, password, `App Connect SAC (${Platform.OS})`);
        await storage.setLastLogin(login);
        setLastLogin(login);
        if (result.kind === 'ok') await start(result.session.token);
        return result;
      },
      async verifyTwoFactor(challenge, code) {
        const session = await api.verifyTwoFactor(challenge, code);
        await start(session.token);
      },
      async logout() {
        await forgetPush(api);
        try {
          await api.logout();
        } catch {
          // Sem rede: o aparelho esquece o token e o servidor expira a sessão sozinho.
        }
        await signOutLocally();
      },
    }),
    [status, me, api, lastLogin, start, signOutLocally],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error('useAuth fora do AuthProvider');
  return value;
}
