import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState, Platform } from 'react-native';
import { createClient } from '../api/client';
import { teamApi, type LoginResult, type Me, type TeamApi } from '../api/team';
import { apiBaseUrl } from '../config';
import { clearReminders, forgetPush, registerPush } from '../lib/notify';
import { askBiometric, biometricInfo, type BiometricInfo } from './biometrics';
import { storage } from './storage';

/** Voltando de mais de 5 minutos em segundo plano, pede a biometria de novo. */
const RELOCK_MS = 5 * 60 * 1000;

type Status = 'loading' | 'signedOut' | 'locked' | 'signedIn';

type AuthValue = {
  status: Status;
  me: Me | null;
  api: TeamApi;
  lastLogin: string | null;
  /** Face ID / digital do aparelho e se a pessoa ligou a trava (ADR 0667). */
  biometric: BiometricInfo & { enabled: boolean };
  unlock(): Promise<boolean>;
  setBiometric(on: boolean): Promise<boolean>;
  login(login: string, password: string): Promise<LoginResult>;
  verifyTwoFactor(challenge: string, code: string): Promise<void>;
  logout(): Promise<void>;
};

const AuthContext = createContext<AuthValue | null>(null);

/**
 * Sessão da equipe no aparelho (ADR 0660): o token Bearer fica no cofre do celular; um 401 em qualquer pedido
 * volta para o login. Com a trava ligada em Conta, o app pede Face ID / digital ao abrir e ao voltar de mais de
 * 5 minutos em segundo plano (ADR 0667); a biometria só destrava o token do cofre, a senha nunca é guardada.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('loading');
  const [me, setMe] = useState<Me | null>(null);
  const [lastLogin, setLastLogin] = useState<string | null>(null);
  const tokenRef = useRef<string | null>(null);
  const [bio, setBio] = useState<BiometricInfo>({ available: false, label: 'biometria' });
  const [bioEnabled, setBioEnabled] = useState(false);
  const backgroundAt = useRef<number | null>(null);

  const signOutLocally = useCallback(async () => {
    tokenRef.current = null;
    setMe(null);
    setStatus('signedOut');
    await storage.setToken(null);
    await clearReminders();
  }, []);

  const api = useMemo(
    () =>
      teamApi(
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
      if (!token) throw new Error('Resposta sem token.');
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
      const [info, enabled, remembered] = await Promise.all([biometricInfo(), storage.getBiometric(), storage.getLastLogin()]);
      if (!alive) return;
      setBio(info);
      setLastLogin(remembered);
      const locked = enabled && info.available;
      setBioEnabled(locked);
      const token = await storage.getToken();
      if (!token) {
        if (alive) setStatus('signedOut');
        return;
      }
      tokenRef.current = token;
      if (locked) {
        // A agenda só carrega depois do Face ID / digital.
        if (alive) setStatus('locked');
        return;
      }
      try {
        const data = await api.me();
        if (alive) {
          setMe(data);
          setStatus('signedIn');
        }
      } catch {
        // Sem rede: segue com o token guardado (um 401 já teria limpado a sessão).
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

  useEffect(() => {
    const sub = AppState.addEventListener('change', (next) => {
      if (next === 'background') {
        backgroundAt.current = Date.now();
      } else if (next === 'active' && backgroundAt.current !== null) {
        const away = Date.now() - backgroundAt.current;
        backgroundAt.current = null;
        if (bioEnabled && tokenRef.current && away > RELOCK_MS) {
          setStatus((current) => (current === 'signedIn' ? 'locked' : current));
        }
      }
    });
    return () => sub.remove();
  }, [bioEnabled]);

  const unlock = useCallback(async () => {
    if (!(await askBiometric(bio.label))) return false;
    backgroundAt.current = null;
    try {
      setMe(await api.me());
    } catch {
      // Sem rede: entra com o token guardado; um 401 já teria limpado a sessão.
    }
    if (tokenRef.current) setStatus('signedIn');
    return true;
  }, [api, bio]);

  const value = useMemo<AuthValue>(
    () => ({
      status,
      me,
      api,
      lastLogin,
      biometric: { ...bio, enabled: bioEnabled },
      unlock,
      async setBiometric(on) {
        if (on && !(bio.available && (await askBiometric(bio.label)))) return false;
        await storage.setBiometric(on);
        setBioEnabled(on);
        return true;
      },
      async login(login, password) {
        const result = await api.login(login, password, `App Meu Dia (${Platform.OS})`);
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
          // Sem rede o aparelho esquece o token mesmo assim; o servidor expira a sessão sozinho.
        }
        await signOutLocally();
      },
    }),
    [status, me, api, lastLogin, bio, bioEnabled, unlock, start, signOutLocally],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error('useAuth fora do AuthProvider');
  return value;
}
