import { File, Paths } from 'expo-file-system';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import * as Sharing from 'expo-sharing';
import { Platform } from 'react-native';
import { ApiError, type UploadFile } from '../api/client';
import { dialog } from '../ui/Dialog';
import { MAX_PHOTOS, mimeFor, PHOTO_SIDE, photoName, safeFileName } from './fileNames';

export { MAX_PHOTOS } from './fileNames';


export type PhotoSource = 'camera' | 'library';

/** Tira uma foto ou escolhe várias da galeria, já reduzidas em JPEG. Devolve [] se a pessoa desistir. */
export async function pickPhotos(source: PhotoSource, room: number): Promise<UploadFile[]> {
  if (room <= 0) {
    await dialog.notice({ title: 'Limite de fotos', message: `Envie até ${MAX_PHOTOS} fotos por vez.`, tone: 'info' });
    return [];
  }
  const permission = source === 'camera' ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
  if (!permission.granted) {
    await dialog.notice({
      title: source === 'camera' ? 'Sem acesso à câmera' : 'Sem acesso às fotos',
      message: 'Libere o acesso nos Ajustes do celular, no app Connect SAC, e tente de novo.',
      tone: 'error',
    });
    return [];
  }
  const options: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.7, exif: false, allowsMultipleSelection: source === 'library', selectionLimit: room };
  const result = source === 'camera' ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
  if (result.canceled) return [];
  const picked = result.assets.slice(0, room);
  return Promise.all(picked.map((asset, i) => shrink(asset, i)));
}


async function shrink(asset: ImagePicker.ImagePickerAsset, index: number): Promise<UploadFile> {
  try {
    const context = ImageManipulator.manipulate(asset.uri);
    if (Math.max(asset.width, asset.height) > PHOTO_SIDE) {
      context.resize(asset.width >= asset.height ? { width: PHOTO_SIDE } : { height: PHOTO_SIDE });
    }
    const image = await context.renderAsync();
    const saved = await image.saveAsync({ compress: 0.8, format: SaveFormat.JPEG });
    return { uri: saved.uri, name: photoName(index), type: 'image/jpeg' };
  } catch {
    return { uri: asset.uri, name: asset.fileName ?? photoName(index), type: asset.mimeType ?? 'image/jpeg' };
  }
}

/** Baixa o anexo com o token no cabeçalho para o cache do app e abre a folha do sistema (ver, salvar, enviar). */
export async function openAttachment(url: string, headers: Record<string, string>, name: string): Promise<void> {
  if (Platform.OS === 'web') throw new ApiError(0, 'unsupported', 'Abra este anexo pelo celular.');
  let file: File;
  try {
    file = await File.downloadFileAsync(url, new File(Paths.cache, safeFileName(name)), { headers, idempotent: true });
  } catch {
    throw new ApiError(0, 'download_failed', 'Não foi possível abrir o anexo. Tente de novo.');
  }
  if (!(await Sharing.isAvailableAsync())) throw new ApiError(0, 'unsupported', 'Este aparelho não consegue abrir o anexo.');
  const mimeType = mimeFor(name);
  await Sharing.shareAsync(file.uri, { mimeType, dialogTitle: name, UTI: mimeType === 'application/pdf' ? 'com.adobe.pdf' : mimeType.startsWith('image/') ? 'public.image' : undefined });
}
