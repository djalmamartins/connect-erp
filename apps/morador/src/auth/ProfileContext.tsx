import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import type { UploadFile } from '../api/client';
import type { Onboarding, Privacy } from '../api/resident';
import { useAuth } from './AuthContext';

type ProfileValue = {
  /** null enquanto carrega. */
  onboarding: Onboarding | null;
  /** Muda a cada foto nova, para o Image não mostrar a antiga do cache. */
  photoVersion: number;
  completeOnboarding(): Promise<void>;
  setPhoto(file: UploadFile): Promise<void>;
  removePhoto(): Promise<void>;
  /** Aceite dos termos (LGPD, ADR 0600). null enquanto carrega ou se o servidor ainda não tiver a rota. */
  privacy: Privacy | null;
  acceptTerms(marketing: boolean): Promise<void>;
  setMarketing(marketing: boolean): Promise<void>;
};

const ProfileContext = createContext<ProfileValue | null>(null);

/**
 * Primeiro acesso e foto do perfil (por conta, GET /resident/onboarding). Se o servidor ainda não
 * tiver a rota ou estiver fora do ar, o app não prende o morador nas boas-vindas.
 */
export function ProfileProvider({ children }: { children: ReactNode }) {
  const { status, api, me } = useAuth();
  const [onboarding, setOnboarding] = useState<Onboarding | null>(null);
  const [photoVersion, setPhotoVersion] = useState(1);
  const [privacy, setPrivacy] = useState<Privacy | null>(null);
  const userId = me?.user.id ?? null;

  useEffect(() => {
    if (status !== 'signedIn' || userId === null) {
      setOnboarding(null);
      return;
    }
    let alive = true;
    api
      .onboarding()
      .then((data) => alive && setOnboarding({ completed: !!data?.completed, has_photo: !!data?.has_photo }))
      .catch(() => alive && setOnboarding({ completed: true, has_photo: false }));
    return () => {
      alive = false;
    };
  }, [status, userId, api]);

  useEffect(() => {
    if (status !== 'signedIn' || userId === null) {
      setPrivacy(null);
      return;
    }
    let alive = true;
    api
      .privacy()
      .then((data) => alive && setPrivacy(data))
      .catch(() => alive && setPrivacy(null));
    return () => {
      alive = false;
    };
  }, [status, userId, api]);

  const acceptTerms = useCallback(
    async (marketing: boolean) => {
      setPrivacy(await api.acceptTerms(marketing));
    },
    [api],
  );

  const setMarketing = useCallback(
    async (marketing: boolean) => {
      setPrivacy(await api.setMarketing(marketing));
    },
    [api],
  );

  const completeOnboarding = useCallback(async () => {
    setOnboarding((o) => ({ has_photo: o?.has_photo ?? false, completed: true }));
    await api.completeOnboarding().catch(() => undefined);
  }, [api]);

  const setPhoto = useCallback(
    async (file: UploadFile) => {
      await api.uploadPhoto(file);
      setOnboarding((o) => ({ completed: o?.completed ?? false, has_photo: true }));
      setPhotoVersion((v) => v + 1);
    },
    [api],
  );

  const removePhoto = useCallback(async () => {
    await api.deletePhoto();
    setOnboarding((o) => ({ completed: o?.completed ?? true, has_photo: false }));
    setPhotoVersion((v) => v + 1);
  }, [api]);

  const value = useMemo(
    () => ({ onboarding, photoVersion, completeOnboarding, setPhoto, removePhoto, privacy, acceptTerms, setMarketing }),
    [onboarding, photoVersion, completeOnboarding, setPhoto, removePhoto, privacy, acceptTerms, setMarketing],
  );
  return <ProfileContext.Provider value={value}>{children}</ProfileContext.Provider>;
}

export function useProfile(): ProfileValue {
  const value = useContext(ProfileContext);
  if (!value) {
    throw new Error('useProfile fora do ProfileProvider');
  }
  return value;
}
