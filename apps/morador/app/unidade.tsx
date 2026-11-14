import { router } from 'expo-router';
import { useAuth } from '../src/auth/AuthContext';
import { Body, Card, CardTitle, Screen } from '../src/ui/kit';

export default function Unidade() {
  const { me, residentId, selectResident } = useAuth();
  return (
    <Screen>
      {me?.links.map((link) => (
        <Card
          key={link.resident_id}
          onPress={async () => {
            await selectResident(link.resident_id);
            router.back();
          }}
          accessibilityLabel={`${link.condominium_name}, ${link.unit_label}`}
          style={link.resident_id === residentId ? { borderColor: '#07884c', borderWidth: 2 } : undefined}
        >
          <CardTitle>{link.unit_label}</CardTitle>
          <Body muted>{link.condominium_name}</Body>
        </Card>
      ))}
    </Screen>
  );
}
