import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import type { UploadFile } from '../api/client';
import { dialog } from '../ui/Dialog';

export type PhotoSource = 'camera' | 'library';

/** Lado maior da foto da vistoria enviada (a câmera do iPhone sai com 4 mil pixels e vários MB). */
export const UPLOAD_SIDE = 1600;

/**
 * Tira ou escolhe a foto de um item da vistoria. Reduz no aparelho antes de mandar; o servidor regrava a imagem
 * e tira o EXIF. A posição do aparelho vai à parte, junto com a foto. Devolve null se a pessoa desistir.
 */
export async function pickPhoto(source: PhotoSource): Promise<UploadFile | null> {
  const permission =
    source === 'camera' ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
  if (!permission.granted) {
    await dialog.notice({
      title: source === 'camera' ? 'Sem acesso à câmera' : 'Sem acesso às fotos',
      message: 'Libere o acesso nos Ajustes do celular, no app Meu Dia Connect, e tente de novo.',
      tone: 'error',
    });
    return null;
  }
  const options: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.7, exif: false };
  const result = source === 'camera' ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
  const asset = result.canceled ? null : result.assets[0];
  if (!asset) return null;
  try {
    const context = ImageManipulator.manipulate(asset.uri);
    if (Math.max(asset.width, asset.height) > UPLOAD_SIDE) {
      context.resize(asset.width >= asset.height ? { width: UPLOAD_SIDE } : { height: UPLOAD_SIDE });
    }
    const image = await context.renderAsync();
    const saved = await image.saveAsync({ compress: 0.8, format: SaveFormat.JPEG });
    return { uri: saved.uri, name: 'vistoria.jpg', type: 'image/jpeg' };
  } catch {
    return { uri: asset.uri, name: asset.fileName ?? 'vistoria.jpg', type: asset.mimeType ?? 'image/jpeg' };
  }
}
