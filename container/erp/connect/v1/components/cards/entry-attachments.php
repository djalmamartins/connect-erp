<?php
/**
 * Anexos do lançamento (ADR 0174): nota fiscal, comprovante de pagamento, recibo ou outro documento, guardados no
 * Disco Virtual. Envio por formulário multipart com CSRF (EntryAttachmentScreens); baixar e abrir só com permissão.
 * Os anexos entram numerados no livro caixa do mês (CN-007).
 * @var array{entryId:int, back:string, items:list<array>, canManage:bool, kinds:array<string,string>, error:?string} $attachments
 */
$base = erp_url('/financeiro/lancamentos/' . (int)$attachments['entryId'] . '/anexos');
$size = static fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
$inline = \Source\Controllers\Erp\Connect\EntryAttachmentScreens::INLINE;
?>
<section class="erp-card erp-entry-attachments" id="anexos" aria-labelledby="entry-att-title">
    <div class="erp-card__head"><div><h2 id="entry-att-title">Notas e comprovantes</h2><p>Saem numerados no livro caixa do mês</p></div></div>
    <?php if ($attachments['error'] !== null): ?>
        <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><?= $this->e($attachments['error']) ?></div></div>
    <?php endif; ?>
    <?php if ($attachments['items'] === []): ?>
        <p class="erp-field__hint">Nenhum anexo ainda. Anexe a nota fiscal e o comprovante de pagamento para o livro caixa sair completo.</p>
    <?php else: ?>
        <ul class="erp-list">
            <?php foreach ($attachments['items'] as $item): $href = $base . '/' . (int)$item['id']; ?>
                <li><span class="erp-list__icon"><?= $icon($item['kind'] === 'nota_fiscal' ? 'receipt' : 'file', 'erp-icon--sm') ?></span>
                    <div class="erp-list__body"><strong><?= $this->e($item['kind_label']) ?></strong>
                        <small><a href="<?= $this->e($href . (in_array($item['mime'], $inline, true) ? '?ver=1' : '')) ?>" target="_blank" rel="noopener"><?= $this->e($item['original_name']) ?></a> · <?= $this->e($size((int)$item['size_bytes'])) ?></small></div>
                    <span class="erp-list__meta" style="display:flex;gap:4px;align-items:center">
                        <a class="erp-icon-button" href="<?= $this->e($href) ?>" aria-label="Baixar <?= $this->e($item['original_name']) ?>"><?= $icon('download', 'erp-icon--sm') ?></a>
                        <?php if ($attachments['canManage']): ?>
                            <form method="post" action="<?= $this->e($href . '/excluir') ?>" style="display:inline" onsubmit="return confirm('Remover este anexo do lançamento? O arquivo continua no Disco Virtual.')">
                                <?= csrf_input() ?><input type="hidden" name="voltar" value="<?= $this->e($attachments['back']) ?>">
                                <button type="submit" class="erp-icon-button" aria-label="Remover <?= $this->e($item['original_name']) ?>"><?= $icon('trash', 'erp-icon--sm') ?></button>
                            </form>
                        <?php endif; ?>
                    </span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($attachments['canManage']): ?>
        <form class="erp-form" method="post" action="<?= $this->e($base) ?>" enctype="multipart/form-data" style="margin-top:12px">
            <?= csrf_input() ?><input type="hidden" name="voltar" value="<?= $this->e($attachments['back']) ?>">
            <div class="erp-field"><label for="entry-att-kind">Tipo</label>
                <select class="erp-input" id="entry-att-kind" name="kind" required>
                    <?php foreach ($attachments['kinds'] as $value => $label): ?><option value="<?= $this->e($value) ?>"><?= $this->e($label) ?></option><?php endforeach; ?>
                </select></div>
            <div class="erp-field"><label for="entry-att-file">Arquivo</label>
                <input class="erp-input" type="file" id="entry-att-file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.xml,.docx,.xlsx,.odt,.ods,.txt,.csv" required aria-describedby="entry-att-hint">
                <span class="erp-field__hint" id="entry-att-hint">PDF, foto (JPG, PNG, WebP) ou XML da NF-e, até 20 MB.</span></div>
            <button type="submit" class="erp-btn erp-btn--primary erp-btn--block"><?= $icon('upload', 'erp-icon--sm') ?>Anexar</button>
        </form>
    <?php endif; ?>
</section>
