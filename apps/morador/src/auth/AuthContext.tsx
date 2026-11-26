import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState, Platform } from 'react-native';
import { createClient, type ApiClient } from '../api/client';
import { residentApi, type LoginResult, type Me, type ResidentApi } from '../api/resident';
import { apiBaseUrl } from '../config';
import { askBiometric, biometricInfo, type BiometricInfo } from './biometrics';
import { forgetPush, registerPush } from '../lib/push';
import { tokenStorage } from './storage';

type Status = 'loading' | 'signedOut' | 'locked' | 'signedIn';

/** Depois de quanto tempo em segundo plano o app volta a pedir Face ID / digital. */
const RELOCK_MS = 5 * 60 * 1000;

type AuthValue = {
  status: Status;
  me: Me | null;
  api: ResidentApi;
  client: ApiClient;
  residentId: number | null;
  biometric: BiometricInfo & { enabled: boolean };
  lastLogin: string | null;
  login(login: string, password: string, useBiometric?: boolean): Promise<LoginResult>;
  unlock(): Promise<boolean>;
  setBiometric(on: boolean): Promise<boolean>;
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
  const [bio, setBio] = useState<BiometricInfo>({ available: false, label: 'biometria' });
  const [bioEnabled, setBioEnabled] = useState(false);
  const [lastLogin, setLastLogin] = useState<string | null>(null);
  const wantBiometric = useRef(false);
  const backgroundAt = useRef<number | null>(null);

  const signOutLocally = useCallback(async () => {
    tokenRef.current = null;
    residentRef.current = null;
    setMe(null);
    setResidentId(null);
    setStatus('signedOut');
    await tokenStorage.setToken(null);
    await tokenStorage.setResidentId(null);
    await tokenStorage.setBiometric(false);
    setBioEnabled(false);
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
      if (wantBiometric.current) {
        wantBiometric.current = false;
        // Confirma uma vez agora: no iPhone é aqui que o sistema pede a permissão do Face ID.
        const ok = bio.available && (await askBiometric(bio.label));
        await tokenStorage.setBiometric(ok);
        setBioEnabled(ok);
      }
      setStatus('signedIn');
    },
    [loadMe, bio],
  );

  const unlock = useCallback(async () => {
    if (!(await askBiometric(bio.label))) {
      return false;
    }
    backgroundAt.current = null;
    try {
      await loadMe();
    } catch {
      // Sem rede: entra com o token guardado; um 401 já teria limpado a sessão.
    }
    if (tokenRef.current) {
      setStatus('signedIn');
    }
    return true;
  }, [bio, loadMe]);

  // Voltando de mais de 5 minutos em segundo plano, pede a biometria de novo.
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

  useEffect(() => {
    let alive = true;
    (async () => {
      const [info, enabled, remembered] = await Promise.all([biometricInfo(), tokenStorage.getBiometric(), tokenStorage.getLastLogin()]);
      if (!alive) return;
      setBio(info);
      setLastLogin(remembered);
      const locked = enabled && info.available;
      setBioEnabled(locked);
      const token = await tokenStorage.getToken();
      if (!token) {
        if (alive) setStatus('signedOut');
        return;
      }
      tokenRef.current = token;
      residentRef.current = await tokenStorage.getResidentId();
      setResidentId(residentRef.current);
      if (locked) {
        // Os dados só carregam depois do Face ID / digital.
        if (alive) setStatus('locked');
        return;
      }
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

  // Push dos chamados (ADR 0641): registra o celular assim que a sessão está aberta.
  useEffect(() => {
    if (status === 'signedIn') {
      void registerPush(api);
    }
  }, [status, api]);

  const value = useMemo<AuthValue>(
    () => ({
      status,
      me,
      api,
      client,
      residentId,
      biometric: { ...bio, enabled: bioEnabled },
      lastLogin,
      unlock,
      async setBiometric(on) {
        if (on && !(bio.available && (await askBiometric(bio.label)))) {
          return false;
        }
        await tokenStorage.setBiometric(on);
        setBioEnabled(on);
        return true;
      },
      async login(login, password, useBiometric = false) {
        wantBiometric.current = useBiometric;
        const result = await api.login(login, password, `App Connect Morador (${Platform.OS})`);
        await tokenStorage.setLastLogin(login);
        setLastLogin(login);
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
        await forgetPush(api);
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
    [status, me, api, client, residentId, bio, bioEnabled, lastLogin, unlock, startSession, signOutLocally, loadMe],
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
