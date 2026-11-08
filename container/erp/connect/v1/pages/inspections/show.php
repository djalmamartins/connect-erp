<?php
/**
 * Vistoria: checklist (em andamento) ou resultado, conclusão e envio do relatório.
 * @var array $inspection InspectionService::get()
 * @var array<string, string> $errors
 * @var bool $canPerform @var string $base
 */
use Source\Services\Inspections\InspectionService;

$this->layout('default');
$this->insert('pages/inspections/partials/assets', ['flash' => $flash]);
$id = (int)$inspection['id'];
$url = static fn(string $path = ''): string => erp_url($base . '/' . $id . $path);
$editable = (bool)$inspection['editable'];
$checkin = $inspection['checkin'];
$total = count($inspection['items']);
$answered = $total - (int)$inspection['counts']['pending'];
$statusLabels = ['in_progress' => ['Em andamento', 'info'], 'completed' => ['Concluída', 'success'], 'cancelled' => ['Cancelada', 'plain']];
[$statusLabel, $statusTone] = $statusLabels[$inspection['status']];
$checkinTone = ['inside' => 'success', 'no_reference' => 'info', 'outside' => 'warning', 'no_signal' => 'warning'][$checkin['status']];
$old = [];
ob_start(); ?>
    <a class="erp-btn" href="<?= erp_url($base) ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Vistorias</a>
    <a class="erp-btn" href="<?= $url('/relatorio') ?>"><?= $icon('eye', 'erp-icon--sm') ?>Relatório</a>
    <a class="erp-btn" href="<?= $url('/relatorio/pdf') ?>"><?= $icon('download', 'erp-icon--sm') ?>PDF</a>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Vistoria nº ' . $id . ' · ' . $inspection['template'],
    'pageTitle' => $inspection['condominium'],
    'pageActions' => $actions,
]);
?>
<section class="erp-card">
    <dl class="erp-detail-grid">
        <div><dt>Situação</dt><dd><span class="erp-badge erp-badge--<?= $statusTone ?>"><?= $statusLabel ?></span></dd></div>
        <div><dt>Responsável</dt><dd><?= $this->e($inspection['inspector'] ?: '—') ?></dd></div>
        <div><dt>Check-in</dt><dd><?= date('d/m/Y H:i', strtotime($checkin['at'])) ?></dd></div>
        <div><dt>Localização</dt><dd><span class="erp-badge erp-badge--<?= $checkinTone ?>"><?= $this->e(InspectionService::CHECKIN[$checkin['status']]) ?></span>
            <?php if ($checkin['distance_m'] !== null): ?><small class="insp-muted"><?= number_format((float)$checkin['distance_m'], 0, ',', '.') ?> m</small><?php endif; ?></dd></div>
    </dl>
    <?php if (!empty($checkin['note'])): ?>
        <div class="erp-alert erp-alert--warning" style="margin-top:12px"><div><strong>Justificativa do check-in</strong><?= $this->e((string)$checkin['note']) ?></div></div>
    <?php endif; ?>
    <div style="margin-top:16px">
        <div class="insp-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $total ?>" aria-valuenow="<?= $answered ?>" aria-label="Itens respondidos"><span style="width:<?= $total ? round($answered * 100 / $total) : 0 ?>%"></span></div>
        <small class="insp-muted"><?= $answered ?> de <?= $total ?> itens respondidos · <?= (int)$inspection['counts']['issue'] ?> com problema</small>
    </div>
</section>

<?php if ($inspection['status'] === 'in_progress' && !$editable): ?>
    <div class="erp-alert erp-alert--warning" role="status">Esta vistoria está sendo feita por <?= $this->e($inspection['inspector'] ?: 'outro usuário') ?>. Você pode acompanhar, mas não alterar.</div>
<?php endif; ?>

<section aria-label="Checklist">
    <?php $section = null;
    foreach ($inspection['items'] as $item):
        if ($item['section'] !== $section):
            $section = $item['section']; ?>
            <h2 class="insp-section"><?= $this->e($section) ?></h2>
        <?php endif;
        $itemError = $errors['item_' . $item['id']] ?? '';
        $classes = 'insp-item insp-item--' . $item['result'] . ($itemError ? ' insp-item--error' : ''); ?>
        <article class="<?= $classes ?>" id="item-<?= (int)$item['id'] ?>">
            <div class="insp-item__title">
                <h3><?= $this->e($item['label']) ?></h3>
                <?php if ($item['requires_photo']): ?><span class="erp-badge erp-badge--info erp-badge--plain">Foto obrigatória</span><?php endif; ?>
            </div>
            <?php if ($itemError): ?><div class="erp-field__error" role="alert"><?= $this->e($itemError) ?></div><?php endif; ?>

            <?php if ($item['photos'] !== []): ?>
                <div class="insp-photos">
                    <?php foreach ($item['photos'] as $photo): ?>
                        <figure>
                            <a href="<?= $url('/fotos/' . (int)$photo['id']) ?>" target="_blank" rel="noopener"><img src="<?= $url('/fotos/' . (int)$photo['id']) ?>" alt="Foto de <?= $this->e($item['label']) ?>" width="96" height="96" loading="lazy"></a>
                            <?php if ($editable): ?>
                                <form method="post" action="<?= $url('/fotos/' . (int)$photo['id'] . '/excluir') ?>" onsubmit="return confirm('Remover esta foto?')">
                                    <?= csrf_input() ?><input type="hidden" name="item" value="<?= (int)$item['id'] ?>">
                                    <button type="submit" aria-label="Remover foto">×</button>
                                </form>
                            <?php endif; ?>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($editable): ?>
                <form class="erp-form" method="post" action="<?= $url('/itens/' . (int)$item['id']) ?>" enctype="multipart/form-data" data-insp-item-form>
                    <?= csrf_input() ?>
                    <input type="hidden" name="latitude" value=""><input type="hidden" name="longitude" value="">
                    <fieldset class="insp-choices" style="border:0;padding:0;margin:0">
                        <legend class="erp-sr-only">Resultado de <?= $this->e($item['label']) ?></legend>
                        <?php foreach (['ok' => 'Conforme', 'issue' => 'Problema', 'not_applicable' => 'Não se aplica'] as $value => $label):
                            $inputId = 'r-' . $item['id'] . '-' . $value; ?>
                            <span><input type="radio" id="<?= $inputId ?>" name="result" value="<?= $value ?>"<?= $item['result'] === $value ? ' checked' : '' ?>><label for="<?= $inputId ?>"><?= $label ?></label></span>
                        <?php endforeach; ?>
                    </fieldset>
                    <div class="erp-field">
                        <label for="note-<?= (int)$item['id'] ?>">Observação</label>
                        <textarea class="erp-textarea" id="note-<?= (int)$item['id'] ?>" name="note" rows="2" maxlength="1000" placeholder="Obrigatória quando houver problema"><?= $this->e((string)$item['note']) ?></textarea>
                    </div>
                    <div class="insp-file">
                        <label class="erp-sr-only" for="photo-<?= (int)$item['id'] ?>">Foto</label>
                        <input type="file" id="photo-<?= (int)$item['id'] ?>" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment">
                        <button class="erp-btn erp-btn--primary erp-btn--sm" type="submit">Salvar item</button>
                    </div>
                </form>
            <?php else: ?>
                <div><strong><?= $this->e(InspectionService::RESULTS[$item['result']]) ?></strong><?= $item['note'] ? ' — ' . nl2br($this->e((string)$item['note'])) : '' ?></div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($editable): ?>
    <section class="erp-card" id="concluir">
        <div class="erp-card__head"><div><h2>Concluir vistoria</h2><p>Todos os itens precisam de resposta, e os itens com foto obrigatória precisam de pelo menos uma foto.</p></div></div>
        <?php if (!empty($errors) && array_filter(array_keys($errors), static fn($k) => str_starts_with((string)$k, 'item_'))): ?>
            <div class="erp-alert erp-alert--danger" role="alert"><div><strong>Faltam itens</strong>
                <ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($errors as $key => $message): if (!str_starts_with((string)$key, 'item_')) { continue; } ?>
                    <li><a href="#<?= $this->e(str_replace('item_', 'item-', (string)$key)) ?>"><?= $this->e($message) ?></a></li>
                <?php endforeach; ?></ul></div></div>
        <?php endif; ?>
        <form class="erp-form" method="post" action="<?= $url('/concluir') ?>">
            <?= csrf_input() ?>
            <div class="erp-field">
                <label for="insp-summary">Resumo para o relatório</label>
                <textarea class="erp-textarea" id="insp-summary" name="summary" rows="4" maxlength="4000" placeholder="Principais pontos, combinados com o zelador, próximos passos"><?= $this->e((string)($inspection['summary'] ?? '')) ?></textarea>
                <?php if (isset($errors['summary'])): ?><span class="erp-field__error"><?= $this->e($errors['summary']) ?></span><?php endif; ?>
            </div>
            <div class="insp-actions"><button class="erp-btn erp-btn--primary" type="submit"><?= $icon('check', 'erp-icon--sm') ?>Concluir vistoria</button></div>
        </form>
        <details id="cancelar" style="margin-top:16px">
            <summary class="insp-muted">Cancelar esta vistoria</summary>
            <form class="erp-form" method="post" action="<?= $url('/cancelar') ?>" style="margin-top:8px">
                <?= csrf_input() ?>
                <div class="erp-field">
                    <label for="insp-reason">Motivo</label>
                    <input class="erp-input" id="insp-reason" name="reason" maxlength="1000" required>
                    <?php if (isset($errors['reason'])): ?><span class="erp-field__error"><?= $this->e($errors['reason']) ?></span><?php endif; ?>
                </div>
                <div class="insp-actions"><button class="erp-btn erp-btn--danger" type="submit">Cancelar vistoria</button></div>
            </form>
        </details>
    </section>
    <div class="insp-sticky">
        <span class="insp-muted"><?= $answered ?>/<?= $total ?> respondidos</span>
        <a class="erp-btn erp-btn--primary" href="#concluir">Ir para conclusão</a>
    </div>
<?php elseif ($inspection['status'] === 'completed'): ?>
    <?php if (!empty($inspection['summary'])): ?>
        <section class="erp-card"><div class="erp-card__head"><h2>Resumo</h2></div><p><?= nl2br($this->e((string)$inspection['summary'])) ?></p></section>
    <?php endif; ?>
    <section class="erp-card" id="enviar">
        <div class="erp-card__head"><div><h2>Enviar relatório</h2><p>O PDF com fotos vai anexado. Use vírgulas para separar até 10 e-mails (síndico, conselho, zelador).</p></div></div>
        <?php if ($canPerform): ?>
            <form class="erp-form" method="post" action="<?= $url('/enviar') ?>">
                <?= csrf_input() ?>
                <div class="erp-field">
                    <label for="insp-recipients">Destinatários</label>
                    <input class="erp-input" id="insp-recipients" name="recipients" type="text" inputmode="email" autocomplete="off" placeholder="sindico@exemplo.com, conselho@exemplo.com" required<?= isset($errors['recipients']) ? ' aria-invalid="true"' : '' ?>>
                    <?php if (isset($errors['recipients'])): ?><span class="erp-field__error"><?= $this->e($errors['recipients']) ?></span><?php endif; ?>
                </div>
                <div class="erp-field">
                    <label for="insp-message">Mensagem (opcional)</label>
                    <textarea class="erp-textarea" id="insp-message" name="message" rows="3" maxlength="2000"></textarea>
                </div>
                <div class="insp-actions"><button class="erp-btn erp-btn--primary" type="submit">Enviar relatório</button></div>
            </form>
        <?php endif; ?>
        <?php if ($inspection['deliveries'] !== []): ?>
            <h3 style="margin-top:16px;font-size:14px">Envios</h3>
            <ul class="insp-muted">
                <?php foreach ($inspection['deliveries'] as $delivery): ?>
                    <li><?= $this->e($delivery['recipient_email']) ?> · <?= date('d/m/Y H:i', strtotime((string)$delivery['queued_at'])) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
