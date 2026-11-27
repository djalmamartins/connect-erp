import { useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';

/**
 * Cronômetro do tempo dedicado ao chamado, como o da tela do ERP (ADR 0307): começa ao abrir,
 * pausa e zera. Só conta com o app na frente (celular no bolso não soma tempo).
 */
export function useTimer(enabled: boolean) {
  const [seconds, setSeconds] = useState(0);
  const [running, setRunning] = useState(true);
  const active = useRef(AppState.currentState === 'active');

  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      active.current = state === 'active';
    });
    return () => sub.remove();
  }, []);

  useEffect(() => {
    if (!enabled || !running) return;
    const id = setInterval(() => {
      if (active.current) setSeconds((s) => Math.min(s + 1, MAX_SECONDS));
    }, 1000);
    return () => clearInterval(id);
  }, [enabled, running]);

  return { seconds, running, toggle: () => setRunning((r) => !r), reset: () => setSeconds(0) };
}

/** A API aceita até 24 h por resposta (time_spent). */
export const MAX_SECONDS = 86_400;

/** "0:42", "12:05" ou "1:02:09". */
export function clock(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = String(s % 60).padStart(2, '0');
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}
