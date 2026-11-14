import { Alert } from 'react-native';

/** Pergunta antes de uma ação que o morador não desfaz sozinho (cancelar reserva ou visita). */
export function confirm(title: string, message: string, action: string): Promise<boolean> {
  return new Promise((resolve) => {
    Alert.alert(title, message, [
      { text: 'Voltar', style: 'cancel', onPress: () => resolve(false) },
      { text: action, style: 'destructive', onPress: () => resolve(true) },
    ]);
  });
}
