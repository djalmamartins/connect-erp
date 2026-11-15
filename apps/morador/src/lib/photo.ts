import * as ImagePicker from 'expo-image-picker';
import type { UploadFile } from '../api/client';
import { dialog } from '../ui/Dialog';

export type PhotoSource = 'camera' | 'library';

/**
 * Tira ou escolhe a foto do perfil, já recortada em quadrado. O servidor recorta de novo,
 * regrava em JPEG e tira os dados de localização (EXIF). Devolve null se a pessoa desistir.
 */
export async function pickPhoto(source: PhotoSource): Promise<UploadFile | null> {
  const permission =
    source === 'camera' ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
  if (!permission.granted) {
    await dialog.notice({
      title: source === 'camera' ? 'Sem acesso à câmera' : 'Sem acesso às fotos',
      message: 'Libere o acesso nos Ajustes do celular, no app Connect Morador, e tente de novo.',
      tone: 'error',
    });
    return null;
  }
  const options: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], allowsEditing: true, aspect: [1, 1], quality: 0.7, exif: false };
  const result = source === 'camera' ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
  const asset = result.canceled ? null : result.assets[0];
  if (!asset) {
    return null;
  }
  const type = asset.mimeType ?? 'image/jpeg';
  const ext = type === 'image/png' ? 'png' : type === 'image/webp' ? 'webp' : 'jpg';
  return { uri: asset.uri, name: asset.fileName ?? `perfil.${ext}`, type };
}
