import Ionicons from '@expo/vector-icons/Ionicons';
import { LinearGradient } from 'expo-linear-gradient';
import { router, type Href } from 'expo-router';
import { useEffect, useState, type ComponentProps } from 'react';
import { Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { isFeatureOn, type Feature } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { tokenStorage } from '../../src/auth/storage';
import { copyText, nextBoleto, openBoleto } from '../../src/lib/boleto';
import { date, greeting, initials, longToday, money } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Card, CardTitle, ErrorBox, Loading, Screen, SectionTitle } from '../../src/ui/kit';

type IconName = ComponentProps<typeof Ionicons>['name'];
type Shortcut = { label: string; icon: IconName; href: Href; feature: Feature };
type Tile = { label: string; value: string; icon: IconName; href: Href };

const SHORTCUTS: Shortcut[] = [
  { label: 'Reservar', icon: 'calendar', href: '/reservas', feature: 'reservations' },
  { label: 'Ocorrência', icon: 'chatbubble-ellipses', href: '/ocorrencias', feature: 'occurrences' },
  { label: 'Visitante', icon: 'person-add', href: '/visitantes', feature: 'visitors' },
  { label: 'Documentos', icon: 'document-text', href: '/documentos', feature: 'documents' },
];

export default function Home() {
  const { api, client, me, residentId } = useAuth();
  const summary = useResource(() => api.summary(), [residentId]);
  const billing = isFeatureOn(me, 'boletos');
  // Segunda via de cara: a próxima conta com boleto já fica com os botões no cartão verde.
  const charges = useResource(() => (billing ? api.charges() : Promise.resolve(null)), [residentId, billing]);
  const boleto = nextBoleto(charges.data?.lines ?? []);
  const [opening, setOpening] = useState(false);
  const [hidden, setHidden] = useState(false);
  const many = (me?.links.length ?? 0) > 1;

  useEffect(() => {
    void tokenStorage.getHideValues().then(setHidden);
  }, []);

  function toggleHidden() {
    setHidden((v) => {
      void tokenStorage.setHideValues(!v);
      return !v;
    });
  }

  async function secondCopy() {
    if (!boleto || opening) return;
    setOpening(true);
    try {
      await openBoleto(api, client, boleto);
    } finally {
      setOpening(false);
    }
  }

  async function refresh() {
    await Promise.all([summary.reload(), charges.reload()]);
  }
  const current = me?.current ?? null;
  const s = summary.data;
  const name = current?.name ?? me?.user.name ?? '';
  const first = name.split(' ')[0] || 'morador';
  const shortcuts = SHORTCUTS.filter((c) => isFeatureOn(me, c.feature));

  const tiles: Tile[] = [];
  if (s?.deliveries_waiting !== undefined) {
    tiles.push({ label: 'Encomendas para retirar', value: String(s.deliveries_waiting), icon: 'cube', href: '/encomendas' });
  }
  if (s?.visits_today !== undefined) {
    tiles.push({ label: 'Visitas previstas hoje', value: String(s.visits_today), icon: 'people', href: '/visitantes' });
  }

  const avatar = (
    <Pressable onPress={() => router.push('/mais')} accessibilityRole="button" accessibilityLabel="Sua conta" style={styles.avatar}>
      <Text style={styles.avatarText}>{initials(name)}</Text>
    </Pressable>
  );

  return (
    <Screen tab eyebrow={longToday()} title={`${greeting()}, ${first}`} right={avatar} refreshing={summary.refreshing} onRefresh={refresh}>
      <Pressable
        onPress={() => (s?.charges ? router.push('/financeiro') : undefined)}
        accessibilityRole={s?.charges ? 'button' : undefined}
        accessibilityLabel={s?.charges ? (hidden ? 'Ver o financeiro' : `Em aberto: ${money(s.charges.open_cents)}`) : undefined}
        style={({ pressed }) => [styles.heroWrap, pressed && s?.charges ? { transform: [{ scale: 0.985 }] } : null]}
      >
        <LinearGradient colors={[colors.sidebar, '#124c3c', colors.brandStrong]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
          <Image source={require('../../assets/intro-symbol.png')} style={styles.heroMark} resizeMode="contain" accessible={false} />
          <View style={styles.heroTop}>
            <View style={{ flex: 1 }}>
              <Pressable
                onPress={many ? () => router.push('/unidade') : undefined}
                disabled={!many}
                accessibilityRole={many ? 'button' : undefined}
                accessibilityLabel={many ? 'Trocar de unidade' : undefined}
                style={styles.condoRow}
              >
                <Text style={styles.condo} numberOfLines={2}>
                  {current?.condominium_name ?? 'Seu condomínio'}
                </Text>
                {many ? <Ionicons name="chevron-down" size={18} color="rgba(255,255,255,0.85)" /> : null}
              </Pressable>
              {current ? (
                <View style={styles.unitChip}>
                  <Ionicons name="home" size={12} color={colors.accent} />
                  <Text style={styles.unitText}>
                    {current.unit_label}
                    {current.relationship_label ? ` · ${current.relationship_label}` : ''}
                  </Text>
                </View>
              ) : null}
            </View>
          </View>
          {s?.charges ? (
            <View style={styles.heroMoney}>
              <View style={styles.labelRow}>
                <Text style={styles.heroLabel}>Em aberto</Text>
                <Pressable onPress={toggleHidden} hitSlop={12} accessibilityRole="button" accessibilityLabel={hidden ? 'Mostrar valores' : 'Esconder valores'}>
                  <Ionicons name={hidden ? 'eye-off-outline' : 'eye-outline'} size={18} color="rgba(255,255,255,0.8)" />
                </Pressable>
              </View>
              <Text style={styles.heroValue}>{hidden ? 'R$ •••••' : money(s.charges.open_cents)}</Text>
              <View style={styles.heroFoot}>
                <Text style={styles.heroHint}>Próximo vencimento {date(s.charges.next_due_on)}</Text>
                {s.charges.overdue_cents > 0 ? (
                  <View style={styles.overdue}>
                    <Text style={styles.overdueText}>{hidden ? 'Há valor vencido' : `${money(s.charges.overdue_cents)} vencido`}</Text>
                  </View>
                ) : (
                  <Ionicons name="arrow-forward-circle" size={26} color="rgba(255,255,255,0.85)" />
                )}
              </View>
              {boleto?.charge ? (
                <View style={styles.heroActions}>
                  {boleto.charge.pdf_available ? (
                    <Pressable
                      onPress={secondCopy}
                      disabled={opening}
                      accessibilityRole="button"
                      accessibilityLabel={`Segunda via do boleto que vence em ${date(boleto.due_on)}`}
                      style={({ pressed }) => [styles.heroButton, styles.heroButtonMain, (pressed || opening) && { opacity: 0.75 }]}
                    >
                      <Ionicons name="document-text" size={16} color={colors.sidebar} />
                      <Text style={[styles.heroButtonText, { color: colors.sidebar }]}>{opening ? 'Abrindo…' : '2ª via do boleto'}</Text>
                    </Pressable>
                  ) : null}
                  {boleto.charge.digitable_line ? (
                    <Pressable
                      onPress={() => copyText(boleto.charge?.digitable_line as string, 'Código do boleto')}
                      accessibilityRole="button"
                      accessibilityLabel="Copiar o código do boleto"
                      style={({ pressed }) => [styles.heroButton, pressed && { opacity: 0.75 }]}
                    >
                      <Ionicons name="copy-outline" size={16} color="#ffffff" />
                      <Text style={styles.heroButtonText}>Copiar código</Text>
                    </Pressable>
                  ) : null}
                </View>
              ) : null}
            </View>
          ) : null}
        </LinearGradient>
      </Pressable>

      {shortcuts.length > 0 ? (
        <View style={styles.shortcuts}>
          {shortcuts.map((c) => (
            <Pressable key={c.label} onPress={() => router.push(c.href)} accessibilityRole="button" accessibilityLabel={c.label} style={({ pressed }) => [styles.shortcut, pressed && { opacity: 0.7 }]}>
              <View style={styles.shortcutIcon}>
                <Ionicons name={c.icon} size={22} color={colors.brandStrong} />
              </View>
              <Text style={styles.shortcutLabel} numberOfLines={1}>
                {c.label}
              </Text>
            </Pressable>
          ))}
        </View>
      ) : null}

      {summary.loading ? <Loading /> : null}
      {summary.error ? <ErrorBox error={summary.error} onRetry={summary.reload} /> : null}
      {s && s.features.length === 0 ? (
        <Card>
          <CardTitle>Tudo pronto</CardTitle>
          <Body muted>O seu condomínio ainda não ligou nenhuma função do app. Assim que liberar, ela aparece aqui.</Body>
        </Card>
      ) : null}

      {tiles.length > 0 ? (
        <View style={styles.grid}>
          {tiles.map((t) => (
            <Card key={t.label} style={styles.tile} onPress={() => router.push(t.href)} accessibilityLabel={`${t.label}: ${t.value}`}>
              <View style={styles.tileIcon}>
                <Ionicons name={t.icon} size={18} color={colors.brandStrong} />
              </View>
              <Text style={styles.tileValue}>{t.value}</Text>
              <Text style={styles.tileLabel}>{t.label}</Text>
            </Card>
          ))}
        </View>
      ) : null}

      {s?.next_reservations && s.next_reservations.length > 0 ? (
        <>
          <SectionTitle>Próximas reservas</SectionTitle>
          {s.next_reservations.map((r) => (
            <Card key={r.id} onPress={() => router.push('/reservas')}>
              <CardTitle>{r.area}</CardTitle>
              <Body muted>
                {date(r.reserved_on)}
                {r.starts_time ? ` · ${r.starts_time}` : ''}
              </Body>
            </Card>
          ))}
        </>
      ) : null}
      {s?.assemblies && s.assemblies.length > 0 ? (
        <>
          <SectionTitle>Assembleias</SectionTitle>
          {s.assemblies.map((a) => (
            <Card key={a.id} onPress={() => router.push({ pathname: '/assembleias/[id]', params: { id: String(a.id) } })}>
              <CardTitle>{a.title}</CardTitle>
              <Body muted>{date(a.first_call_at)}</Body>
            </Card>
          ))}
        </>
      ) : null}
      {s?.announcements && s.announcements.length > 0 ? (
        <>
          <SectionTitle>Comunicados</SectionTitle>
          {s.announcements.map((a) => (
            <Card key={a.id} onPress={() => router.push('/comunicados')}>
              <CardTitle>{a.subject}</CardTitle>
              <Body muted>{date(a.published_at)}</Body>
            </Card>
          ))}
        </>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  avatar: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.sidebar,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: colors.accent,
    marginBottom: 2,
  },
  avatarText: { fontFamily: fonts.bold, fontSize: 15, color: colors.accent },
  heroWrap: { borderRadius: 24, overflow: 'hidden' },
  hero: { borderRadius: 24, padding: space[5], gap: space[4], overflow: 'hidden' },
  // Ícone da Connect como marca d'água no canto do cartão (pedido do Djalma, 14/11).
  heroMark: {
    position: 'absolute',
    width: 180,
    height: 233,
    right: -34,
    top: -28,
    opacity: 0.16,
    transform: [{ rotate: '-12deg' }],
  },
  heroActions: { flexDirection: 'row', flexWrap: 'wrap', gap: space[2], marginTop: space[3] },
  heroButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 14,
    height: 38,
    borderRadius: 19,
    backgroundColor: 'rgba(255,255,255,0.16)',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: 'rgba(255,255,255,0.35)',
  },
  heroButtonMain: { backgroundColor: colors.accent, borderColor: colors.accent },
  heroButtonText: { fontFamily: fonts.semibold, fontSize: 14, color: '#ffffff' },
  condoRow: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start' },
  labelRow: { flexDirection: 'row', alignItems: 'center', gap: space[2] },
  heroTop: { flexDirection: 'row', alignItems: 'flex-start' },
  condo: { fontFamily: fonts.semibold, fontSize: 17, color: '#ffffff', flexShrink: 1 },
  unitChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    alignSelf: 'flex-start',
    marginTop: space[2],
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(197,161,49,0.16)',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: 'rgba(197,161,49,0.5)',
  },
  unitText: { fontFamily: fonts.semibold, fontSize: 12.5, color: colors.accent },
  heroMoney: { gap: 2 },
  heroLabel: { fontFamily: fonts.medium, fontSize: 13, color: 'rgba(255,255,255,0.7)' },
  heroValue: { fontFamily: fonts.bold, fontSize: 32, letterSpacing: -0.8, color: '#ffffff' },
  heroFoot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: space[1], gap: space[2] },
  heroHint: { fontFamily: fonts.medium, fontSize: 13, color: 'rgba(255,255,255,0.8)', flexShrink: 1 },
  overdue: { backgroundColor: '#fde8e6', borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 4 },
  overdueText: { fontFamily: fonts.semibold, fontSize: 12, color: colors.danger },
  shortcuts: { flexDirection: 'row', justifyContent: 'space-between', gap: space[2], marginTop: space[1] },
  shortcut: { flex: 1, alignItems: 'center', gap: 6 },
  shortcutIcon: {
    width: 56,
    height: 56,
    borderRadius: 18,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
    shadowColor: '#0d2e27',
    shadowOpacity: 0.06,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 3 },
    elevation: 1,
  },
  shortcutLabel: { fontFamily: fonts.medium, fontSize: 12, color: colors.text },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: space[3] },
  tile: { flexBasis: '47%', flexGrow: 1, gap: 4 },
  tileIcon: { width: 32, height: 32, borderRadius: 10, backgroundColor: colors.brandSoft, alignItems: 'center', justifyContent: 'center', marginBottom: 4 },
  tileValue: { fontFamily: fonts.bold, fontSize: 24, letterSpacing: -0.4, color: colors.text },
  tileLabel: { fontFamily: fonts.medium, fontSize: 13, color: colors.muted },
});
