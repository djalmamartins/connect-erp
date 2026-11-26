import { StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../../src/auth/AuthContext';
import { apiBaseUrl } from '../../src/config';
import { meuDiaWebUrl, openMeuDia } from '../../src/lib/apps';
import { colors, fonts, space } from '../../src/theme';
import { dialog } from '../../src/ui/Dialog';
import { Body, Button, Card, IconRow, Screen } from '../../src/ui/kit';

export default function Perfil() {
  const { me, logout } = useAuth();
  return (
    <Screen title="Perfil" tab>
      <Card>
        <Text style={styles.name}>{me?.name ?? ''}</Text>
        <Body muted>{me?.email ?? ''}</Body>
        <View style={{ marginTop: space[2] }}>
          <Body muted>{me?.role?.name ?? 'Equipe'}{me?.can_attend_sac ? ' · atende chamados' : ' · só acompanha chamados'}</Body>
        </View>
      </Card>
      <IconRow icon="calendar" tint={colors.accent} bg={colors.accentSoft} title="Abrir o Meu Dia" subtitle="Agenda do dia, visitas e tarefas" onPress={() => void openMeuDia(meuDiaWebUrl(apiBaseUrl()))} />
      <Body muted style={styles.note}>
        Avisos no celular: chamado novo, resposta de quem pediu e chamado passado para você. Para desligar, use os ajustes de notificação do celular.
      </Body>
      <Button
        title="Sair deste aparelho"
        variant="danger"
        onPress={async () => {
          if (await dialog.confirm({ title: 'Sair do SAC?', message: 'Este celular deixa de receber os avisos dos chamados.', confirm: 'Sair' })) {
            await logout();
          }
        }}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  name: { fontFamily: fonts.bold, fontSize: 20, color: colors.text },
  note: { fontSize: 13, lineHeight: 19 },
});
