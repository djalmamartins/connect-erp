import { useEffect, useState } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { useProfile } from '../auth/ProfileContext';
import { initials } from '../lib/format';
import { colors, fonts } from '../theme';

/** Foto do perfil, ou as iniciais quando não há foto (ou ela não carregou). */
export function Avatar({ name, size = 44 }: { name: string; size?: number }) {
  const { api } = useAuth();
  const { onboarding, photoVersion } = useProfile();
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [photoVersion]);
  const box = { width: size, height: size, borderRadius: size / 2 };
  const showPhoto = !!onboarding?.has_photo && !failed;
  return (
    <View style={[styles.ring, box]}>
      {showPhoto ? (
        <Image
          source={api.photoSource(photoVersion)}
          style={[StyleSheet.absoluteFill, { borderRadius: size / 2 }]}
          onError={() => setFailed(true)}
          accessibilityIgnoresInvertColors
        />
      ) : (
        <Text style={[styles.text, { fontSize: Math.round(size * 0.34) }]}>{initials(name)}</Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  ring: {
    backgroundColor: colors.sidebar,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: colors.accent,
    overflow: 'hidden',
  },
  text: { fontFamily: fonts.bold, color: colors.accent },
});
