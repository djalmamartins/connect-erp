import { useEffect, useState } from 'react';
import { Switch, Text, View } from 'react-native';
import type { Person } from '../../src/api/team';
import { useAuth } from '../../src/auth/AuthContext';
import { usePerson } from '../../src/auth/PersonContext';
import { storage } from '../../src/auth/storage';
import { MORNING_HOUR, REMINDER_MINUTES } from '../../src/lib/day';
import { clearReminders } from '../../src/lib/notify';
import { colors, fonts, space } from '../../src/theme';
import { dialog } from '../../src/ui/Dialog';
import { Body, Button, Card, CardTitle, Choice, Screen } from '../../src/ui/kit';

/** Conta: quem está conectado, de quem é o dia mostrado (equipe), lembretes e sair. */
export default function Account() {
  const { me, api, logout } = useAuth();
  const { person, setPerson } = usePerson();
  const [team, setTeam] = useState<Person[]>([]);
  const [reminders, setReminders] = useState(true);

  useEffect(() => {
    void storage.getReminders().then(setReminders);
    if (me?.can_see_team) api.team().then(setTeam, () => setTeam([]));
  }, [api, me?.can_see_team]);

  async function toggleReminders(on: boolean) {
    setReminders(on);
    await storage.setReminders(on);
    if (!on) await clearReminders();
    dialog.toast(on ? 'Lembretes ligados. Abra a aba Hoje para agendar.' : 'Lembretes desligados');
  }

  async function signOut() {
    const ok = await dialog.confirm({ title: 'Sair do Meu Dia?', message: 'Os lembretes deste celular param até você entrar de novo.', confirm: 'Sair', undo: true, undoText: 'Para voltar, entre de novo.' });
    if (ok) await logout();
  }

  return (
    <Screen tab title="Conta">
      <Card>
        <CardTitle>{me?.name ?? '—'}</CardTitle>
        <Body muted>{me?.email}</Body>
      </Card>

      {me?.can_see_team && team.length > 0 ? (
        <Card>
          <CardTitle>Ver o dia de</CardTitle>
          <Choice
            label="Pessoa da equipe"
            options={[{ value: me.id, label: 'Eu' }, ...team.filter((p) => p.id !== me.id).map((p) => ({ value: p.id, label: p.name }))]}
            value={person?.id ?? me.id}
            onChange={(id) => setPerson(id === me.id ? null : team.find((p) => p.id === id) ?? null)}
          />
          <Body muted>As abas Hoje e Semana passam a mostrar o dia dessa pessoa. Os lembretes do celular continuam sendo só os seus.</Body>
        </Card>
      ) : null}

      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: space[3] }}>
          <View style={{ flex: 1 }}>
            <CardTitle>Lembretes neste celular</CardTitle>
            <Text style={{ fontFamily: fonts.regular, fontSize: 13, color: colors.muted }}>
              {REMINDER_MINUTES} minutos antes de cada compromisso e um resumo às {MORNING_HOUR}h com as tarefas atrasadas.
            </Text>
          </View>
          <Switch value={reminders} onValueChange={toggleReminders} trackColor={{ true: colors.brand, false: colors.border }} accessibilityLabel="Lembretes neste celular" />
        </View>
      </Card>

      <Button title="Sair" variant="danger" onPress={signOut} />
      <Body muted style={{ textAlign: 'center', fontSize: 12 }}>
        Meu Dia · Connect Condomínios
      </Body>
    </Screen>
  );
}
