import Ionicons from '@expo/vector-icons/Ionicons';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { type ImageURISource, Pressable, StyleSheet, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { Inspection, InspectionItem, InspectionResult } from '../../src/api/team';
import { useAuth } from '../../src/auth/AuthContext';
import { answered, blockers, bySection, checkinLabel, needsPhoto, noteError, photoPath, RESULT_LABEL } from '../../src/lib/inspection';
import { currentPosition } from '../../src/lib/location';
import { pickPhoto } from '../../src/lib/photo';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { dialog } from '../../src/ui/Dialog';
import { Button, Card, ErrorBox, Field, Loading, Screen, SectionTitle } from '../../src/ui/kit';
import { PhotoStrip } from '../../src/ui/PhotoStrip';

/**
 * Vistoria pelo app (ADR 0663): os itens do modelo por seção, Conforme / Com problema / Não se aplica, foto por item
 * e Concluir. Tudo pelas rotas /inspections, com as mesmas regras da tela de vistoria do ERP (permissão, acesso ao
 * condomínio, foto obrigatória). Ao concluir, o servidor resolve o chamado da vistoria e abre o das pendências.
 */
export default function InspectionScreen() {
  const { api } = useAuth();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const id = Number(rawId);
  const resource = useResource(() => api.inspection(id), [id]);
  const [data, setData] = useState<Inspection | null>(null);
  const [summary, setSummary] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (resource.data) setData(resource.data);
  }, [resource.data]);

  if (resource.loading && !data) return <Loading />;
  if (resource.error && !data) {
    return (
      <Screen back title="Vistoria">
        <ErrorBox error={resource.error} onRetry={() => void resource.reload()} />
      </Screen>
    );
  }
  if (!data) return <Loading />;
  const insp = data;
  const done = answered(insp.items);
  const missing = blockers(insp.items);

  async function save(item: InspectionItem, result: InspectionResult, note: string): Promise<boolean> {
    const error = noteError(result, note);
    if (error) {
      await dialog.notice({ title: 'Falta a descrição', message: error, tone: 'error' });
      return false;
    }
    try {
      setData(await api.answer(insp.id, item.id, result, note));
      return true;
    } catch (e) {
      await dialog.notice({ title: 'Não deu para salvar', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
      return false;
    }
  }

  async function photo(item: InspectionItem) {
    const choice = await dialog.choose({
      title: 'Foto do item',
      message: item.label,
      options: [
        { label: 'Tirar foto', icon: 'camera-outline' },
        { label: 'Escolher da galeria', icon: 'images-outline' },
      ],
    });
    if (choice === null) return;
    const file = await pickPhoto(choice === 0 ? 'camera' : 'library');
    if (!file) return;
    try {
      const position = await currentPosition();
      await api.addPhoto(insp.id, item.id, file, position ?? undefined);
      setData(await api.inspection(insp.id));
      dialog.toast('Foto enviada');
    } catch (e) {
      await dialog.notice({ title: 'A foto não foi enviada', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
    }
  }

  async function complete() {
    if (missing.length > 0) {
      await dialog.notice({ title: 'Ainda falta', message: missing.slice(0, 5).join('\n') + (missing.length > 5 ? `\ne mais ${missing.length - 5}.` : ''), tone: 'error' });
      return;
    }
    const ok = await dialog.confirm({
      title: 'Concluir a vistoria?',
      message: insp.counts.issue > 0 ? `${insp.counts.issue} itens com problema viram um chamado de pendências.` : 'Todos os itens estão respondidos.',
      confirm: 'Concluir',
      tone: 'info',
    });
    if (!ok) return;
    setBusy(true);
    try {
      setData(await api.completeInspection(insp.id, summary));
      dialog.toast('Vistoria concluída');
    } catch (e) {
      await dialog.notice({ title: 'Não deu para concluir', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
    } finally {
      setBusy(false);
    }
  }

  const finished = insp.status !== 'in_progress';

  return (
    <Screen back eyebrow={`Vistoria · ${insp.template}`} title={insp.condominium} subtitle={checkinLabel(insp)} refreshing={resource.loading} onRefresh={() => void resource.reload()}>
      <View style={styles.progressBox}>
        <Text style={styles.progressText}>
          {finished ? (insp.status === 'completed' ? 'Vistoria concluída' : 'Vistoria cancelada') : `${done} de ${insp.items.length} itens respondidos`}
        </Text>
        <View style={styles.track}>
          <View style={[styles.fill, { width: `${insp.items.length ? Math.round((done / insp.items.length) * 100) : 0}%` }]} />
        </View>
        <Text style={styles.progressLine}>
          {insp.counts.ok} conformes · {insp.counts.issue} com problema · {insp.counts.not_applicable} não se aplica
        </Text>
      </View>

      {bySection(insp.items).map((group) => (
        <View key={group.section} style={{ gap: space[2] }}>
          <SectionTitle>{group.section}</SectionTitle>
          {group.items.map((item) => (
            <ItemCard
              key={item.id}
              item={item}
              photos={item.photos.map((p) => api.photoSource(photoPath(insp.id, p)))}
              editable={insp.editable && !finished}
              onSave={save}
              onPhoto={() => void photo(item)}
            />
          ))}
        </View>
      ))}

      {insp.editable && !finished ? (
        <Card>
          <Field label="Resumo da vistoria (opcional)" value={summary} onChangeText={setSummary} multiline maxLength={4000} placeholder="O que o síndico precisa saber" />
          <Button title={missing.length > 0 ? `Concluir (faltam ${missing.length})` : 'Concluir vistoria'} onPress={() => void complete()} busy={busy} />
        </Card>
      ) : (
        <Button title="Voltar para Hoje" variant="secondary" onPress={() => router.navigate('/')} />
      )}
    </Screen>
  );
}

const CHOICES: InspectionResult[] = ['ok', 'issue', 'not_applicable'];

function ItemCard({
  item,
  photos,
  editable,
  onSave,
  onPhoto,
}: {
  item: InspectionItem;
  photos: ImageURISource[];
  editable: boolean;
  onSave: (item: InspectionItem, result: InspectionResult, note: string) => Promise<boolean>;
  onPhoto: () => void;
}) {
  const [draft, setDraft] = useState<InspectionResult | null>(null);
  const [note, setNote] = useState(item.note ?? '');
  const shown = draft ?? item.result;
  const photoMissing = needsPhoto(item);

  async function choose(result: InspectionResult) {
    if (result === 'issue') {
      setDraft('issue');
      return;
    }
    setDraft(null);
    await onSave(item, result, note);
  }

  return (
    <Card style={item.result === 'issue' ? { borderColor: colors.danger } : undefined}>
      <Text style={styles.itemLabel}>{item.label}</Text>
      <View style={styles.chips}>
        {CHOICES.map((r) => {
          const active = shown === r;
          const tone = r === 'issue' ? colors.danger : r === 'ok' ? colors.brand : colors.muted;
          return (
            <Pressable
              key={r}
              disabled={!editable}
              onPress={() => void choose(r)}
              accessibilityRole="radio"
              accessibilityState={{ selected: active, disabled: !editable }}
              style={[styles.chip, active && { backgroundColor: tone, borderColor: tone }]}
            >
              <Text style={[styles.chipText, active && { color: colors.onBrand }]}>{RESULT_LABEL[r]}</Text>
            </Pressable>
          );
        })}
      </View>
      {shown === 'issue' && editable ? (
        <>
          <Field label="O que está errado" value={note} onChangeText={setNote} multiline maxLength={1000} placeholder="Ex.: lâmpada queimada no hall do 3º andar" />
          {draft === 'issue' || note !== (item.note ?? '') ? (
            <Button
              title="Salvar problema"
              onPress={() =>
                void onSave(item, 'issue', note).then((ok) => {
                  if (ok) setDraft(null);
                })
              }
            />
          ) : null}
        </>
      ) : item.note ? (
        <Text style={styles.note}>{item.note}</Text>
      ) : null}
      <View style={styles.photoRow}>
        <Text style={[styles.photoText, photoMissing && { color: colors.danger }]}>
          {item.photos.length > 0 ? `${item.photos.length} ${item.photos.length === 1 ? 'foto' : 'fotos'}` : item.requires_photo ? 'Foto obrigatória' : 'Sem foto'}
        </Text>
        {editable ? (
          <Pressable onPress={onPhoto} style={styles.photoButton} accessibilityRole="button" accessibilityLabel={`Foto de ${item.label}`}>
            <Ionicons name="camera-outline" size={18} color={colors.brandStrong} />
            <Text style={styles.photoButtonText}>Foto</Text>
          </Pressable>
        ) : null}
      </View>
      <PhotoStrip sources={photos} label={item.label} />
    </Card>
  );
}

const styles = StyleSheet.create({
  progressBox: { backgroundColor: colors.sidebar, borderRadius: radius.xl, padding: space[4], gap: space[2], borderBottomWidth: 3, borderBottomColor: colors.accent },
  progressText: { fontFamily: fonts.semibold, fontSize: 16, color: colors.onBrand },
  track: { height: 8, borderRadius: radius.pill, backgroundColor: 'rgba(255,255,255,0.15)', overflow: 'hidden' },
  fill: { height: 8, borderRadius: radius.pill, backgroundColor: colors.accent },
  progressLine: { fontFamily: fonts.regular, fontSize: 13, color: colors.sidebarInk },
  itemLabel: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text, marginBottom: space[2] },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: space[2] },
  chip: { minHeight: 40, paddingHorizontal: space[3], borderRadius: radius.pill, borderWidth: 1, borderColor: colors.border, justifyContent: 'center', backgroundColor: colors.surface },
  chipText: { fontFamily: fonts.medium, fontSize: 14, color: colors.text },
  note: { fontFamily: fonts.regular, fontSize: 14, color: colors.muted, marginTop: space[2] },
  photoRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: space[2] },
  photoText: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  photoButton: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 40, paddingHorizontal: space[3], borderRadius: radius.pill, backgroundColor: colors.brandSoft },
  photoButtonText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.brandStrong },
});
