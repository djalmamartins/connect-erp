import * as Location from 'expo-location';

/** Posição do aparelho para o check-in e as fotos da vistoria. null se a pessoa negar ou não houver sinal em 10 s. */
export async function currentPosition(): Promise<{ latitude: number; longitude: number; accuracy?: number } | null> {
  try {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status !== 'granted') return null;
    const pos = await Promise.race([
      Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High }),
      new Promise<null>((resolve) => setTimeout(() => resolve(null), 10000)),
    ]);
    if (!pos) return null;
    return { latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy: pos.coords.accuracy ?? undefined };
  } catch {
    return null;
  }
}
