<?php
/**
 * Documentos da unidade (AP-123 certidão de débitos, AP-124 declaração de inadimplência, AP-125
 * comprovante de quitação, AP-130 carta de anuência; ADR 0044 e 0046) com dados reais, no contexto da
 * barra superior. Emitir, conferir pelo código e cancelar usam a API /api/v1/unit-documents (erp.js,
 * data-erp-unit-documents). Com ?documento=ID, mostra o documento gravado para imprimir.
 * @var array $currentPage @var string $documentKind @var array $unitDocuments @var ?array $unitDocument
 * @var list<array> $documentUnits @var ?string $documentError @var bool $canManageDocuments
 * Recibo (AP-040, ADR 0057 e 0073): ?unidade= escolhe a unidade e $receiptEntries traz as contas pagas
 * sem recibo válido para marcar; a emissão manda entry_ids e o servidor confere tudo de novo.
 * @var ?int $receiptUnit @var list<array> $receiptEntries
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$stamp = static fn (?string $value): string => $value ? date('d/m/Y \à\s H:i', (int)strtotime($value)) : '—';
$month = static fn (?string $value): string => $value ? substr($value, 5, 2) . '/' . substr($value, 0, 4) : '—';
$canManageDocuments ??= false;
$doc = $unitDocument ?? null;
$rows = $unitDocuments['data'] ?? [];
$kindRoutes = [];
foreach (\Source\Controllers\Erp\Connect\Panel::UNIT_DOCUMENT_PAGES as $pageId => $kindKey) {
    $kindRoutes[$kindKey] = erp_url($catalog['pages'][$pageId]['route'] ?? '');
}
$kindRoutes['receipt'] = erp_url($catalog['pages'][\Source\Controllers\Erp\Connect\Panel::RECEIPT_PAGE]['route'] ?? '');
$receipt = $documentKind === 'receipt';
$receiptUnit ??= null;
$receiptEntries ??= [];
$intro = [
    'debt_certificate' => 'Emite a certidão de débitos da unidade na data de referência. Sem conta vencida, a certidão sai como "nada consta"; com conta vencida, lista as contas com os encargos.',
    'delinquency_declaration' => 'Declara as contas vencidas em aberto da unidade, com os encargos na data de referência. Só é emitida se houver conta vencida.',
    'quittance' => 'Comprova que todas as contas da unidade até a competência escolhida estão pagas. Com alguma conta em aberto, não é emitido.',
    'consent_letter' => 'Carta em que a administradora declara não haver débito vencido da unidade, para a finalidade informada (por exemplo, venda ou locação).',
    'receipt' => 'Recibo de contas já pagas da unidade, escolhidas uma a uma. Cada conta entra em um recibo válido só; cancelar o recibo libera a conta para outro.',
][$documentKind] ?? '';

ob_start();
if ($doc !== null): ?>
    <a class="erp-btn" href="<?= $this->e($route) ?>">Voltar à lista</a>
    <button type="button" class="erp-btn erp-btn--primary" data-erp-print><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button>
<?php endif;
$actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $doc !== null ? $doc['kind_label'] . ' nº ' . (int)$doc['id'] : $currentPage['name'],
    'pageLead' => $doc !== null ? 'Documento gravado na emissão. O conteúdo não muda depois de emitido.' : $intro,
    'pageActions' => $actions,
]);
?>
<div class="erp-stack" data-erp-unit-documents data-api="<?= $this->e(url('/api/v1/unit-documents')) ?>" data-kind="<?= $this->e($documentKind) ?>" data-route="<?= $this->e($route) ?>" data-kind-routes="<?= $this->e((string)json_encode($kindRoutes, JSON_UNESCAPED_SLASHES)) ?>">
    <?= csrf_input() ?>
    <?php if ($documentError !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($documentError) ?></strong><a href="<?= $this->e($route) ?>">Voltar à lista</a></div></div>
    <?php endif; ?>

    <?php if ($doc !== null):
        $content = $doc['content'] ?? [];
        $cancelled = $doc['status'] === 'cancelled';
        $overdue = $content['overdue'] ?? [];
        $upcoming = $content['upcoming'] ?? [];
        ?>
        <?php if ($cancelled): ?>
            <div class="erp-alert erp-alert--danger" role="status"><?= $icon('alert') ?><div><strong>Documento cancelado em <?= $this->e($stamp($doc['cancelled_at'])) ?></strong><?= $this->e((string)$doc['cancel_reason']) ?></div></div>
        <?php endif; ?>
        <article class="erp-card erp-unit-doc<?= $cancelled ? ' is-cancelled' : '' ?>" aria-labelledby="unit-doc-title">
            <header class="erp-unit-doc__head">
                <div><span class="erp-unit-doc__issuer">Connect · administradora de condomínios</span><h2 id="unit-doc-title"><?= $this->e($doc['kind_label']) ?></h2></div>
                <div class="erp-unit-doc__number">Nº <?= (int)$doc['id'] ?><br><span class="erp-muted">Emitido em <?= $this->e($stamp($doc['issued_at'])) ?></span></div>
            </header>
            <dl class="erp-detail-grid">
                <div><dt>Condomínio</dt><dd><?= $this->e((string)$doc['condominium_name']) ?></dd></div>
                <div><dt>Unidade</dt><dd><?= $this->e((string)$doc['unit_label']) ?></dd></div>
                <div><dt>Data de referência</dt><dd><?= $this->e($date($doc['as_of'])) ?></dd></div>
                <?php if ($doc['kind'] === 'quittance'): ?><div><dt>Competências até</dt><dd><?= $this->e($month((string)$doc['reference'])) ?></dd></div><?php endif; ?>
                <?php if ($doc['kind'] === 'consent_letter'): ?><div><dt>Finalidade</dt><dd><?= $this->e((string)$doc['reference']) ?></dd></div><?php endif; ?>
            </dl>
            <div class="erp-unit-doc__text">
                <?php if ($doc['kind'] === 'receipt'): ?>
                    <p>Recebemos da unidade <strong><?= $this->e((string)$doc['unit_label']) ?></strong> do <?= $this->e((string)$doc['condominium_name']) ?> a quantia de <strong><?= $this->e($brl((int)$doc['total_cents'])) ?></strong>, referente <?= count($content['entries'] ?? []) === 1 ? 'à conta abaixo' : 'às ' . count($content['entries'] ?? []) . ' contas abaixo' ?>, pagas conforme os registros da administradora. Damos quitação dos valores listados.</p>
                <?php elseif ($doc['kind'] === 'quittance'): ?>
                    <p>Declaramos que todas as contas da unidade <strong><?= $this->e((string)$doc['unit_label']) ?></strong> do <?= $this->e((string)$doc['condominium_name']) ?> com competência até <strong><?= $this->e($month((string)$doc['reference'])) ?></strong> estão pagas: <?= (int)($content['paid_entries'] ?? 0) ?> conta(s), no total de <?= $this->e($brl((int)($content['paid_cents'] ?? 0))) ?>, conforme os registros da administradora em <?= $this->e($date($doc['as_of'])) ?>.</p>
                <?php elseif ($doc['kind'] === 'consent_letter'): ?>
                    <p>Declaramos, para fins de <strong><?= $this->e((string)$doc['reference']) ?></strong>, que a unidade <strong><?= $this->e((string)$doc['unit_label']) ?></strong> do <?= $this->e((string)$doc['condominium_name']) ?> não tem contas vencidas em aberto em <?= $this->e($date($doc['as_of'])) ?>, conforme os registros da administradora. Nada temos a opor.</p>
                <?php elseif ($doc['result'] === 'clear'): ?>
                    <p class="erp-unit-doc__clear">Nada consta.</p>
                    <p>Certificamos que a unidade <strong><?= $this->e((string)$doc['unit_label']) ?></strong> do <?= $this->e((string)$doc['condominium_name']) ?> não tem contas vencidas em aberto em <?= $this->e($date($doc['as_of'])) ?>, conforme os registros da administradora.</p>
                <?php else: ?>
                    <p><?= $doc['kind'] === 'delinquency_declaration' ? 'Declaramos' : 'Certificamos' ?> que a unidade <strong><?= $this->e((string)$doc['unit_label']) ?></strong> do <?= $this->e((string)$doc['condominium_name']) ?> tem <?= count($overdue) ?> conta(s) vencida(s) em aberto em <?= $this->e($date($doc['as_of'])) ?>, no total atualizado de <strong><?= $this->e($brl((int)$doc['total_cents'])) ?></strong> (<?= $this->e($brl((int)($content['overdue_cents'] ?? 0))) ?> de principal e <?= $this->e($brl((int)($content['charges_cents'] ?? 0))) ?> de encargos).</p>
                <?php endif; ?>
            </div>
            <?php if ($overdue !== []): ?>
                <div class="erp-table-wrap" role="region" aria-label="Contas vencidas" tabindex="0"><table class="erp-table">
                    <caption class="erp-unit-doc__caption">Contas vencidas</caption>
                    <thead><tr><th>Conta</th><th>Competência</th><th>Vencimento</th><th class="is-num">Valor</th><th class="is-num">Correção</th><th class="is-num">Multa</th><th class="is-num">Juros</th><th class="is-num">Atualizado</th></tr></thead>
                    <tbody><?php foreach ($overdue as $o): ?><tr>
                        <td><?= $this->e((string)$o['description']) ?></td><td><?= $this->e($month((string)$o['competence'])) ?></td><td><?= $this->e($date((string)$o['due_on'])) ?> <span class="erp-muted">(<?= (int)($o['days_late'] ?? 0) ?> d)</span></td>
                        <td class="is-num"><?= $this->e($brl((int)$o['amount_cents'])) ?></td><td class="is-num"><?= $this->e($brl((int)($o['correction_cents'] ?? 0))) ?></td><td class="is-num"><?= $this->e($brl((int)($o['fine_cents'] ?? 0))) ?></td><td class="is-num"><?= $this->e($brl((int)($o['interest_cents'] ?? 0))) ?></td><td class="is-num"><strong><?= $this->e($brl((int)($o['total_cents'] ?? $o['amount_cents']))) ?></strong></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
                <?php if (($content['missing_index'] ?? []) !== []): ?><p class="erp-muted">Sem IPCA lançado de <?= $this->e(implode(', ', array_map($month, $content['missing_index']))) ?>: a correção desses meses entrou como zero.</p><?php endif; ?>
            <?php endif; ?>
            <?php if ($doc['kind'] === 'receipt' && ($content['entries'] ?? []) !== []): ?>
                <div class="erp-table-wrap" role="region" aria-label="Contas do recibo" tabindex="0"><table class="erp-table">
                    <caption class="erp-unit-doc__caption">Contas pagas</caption>
                    <thead><tr><th scope="col">Conta</th><th scope="col">Competência</th><th scope="col">Vencimento</th><th scope="col">Pago em</th><th scope="col" class="is-num">Valor</th></tr></thead>
                    <tbody><?php foreach ($content['entries'] as $p): ?><tr><td><?= $this->e((string)$p['description']) ?></td><td><?= $this->e($month((string)$p['competence'])) ?></td><td><?= $this->e($date((string)$p['due_on'])) ?></td><td><?= $this->e($date((string)$p['paid_on'])) ?><?= ($p['bank_account'] ?? null) ? '<br><span class="erp-muted">' . $this->e((string)$p['bank_account']) . '</span>' : '' ?></td><td class="is-num"><?= $this->e($brl((int)$p['amount_cents'])) ?></td></tr><?php endforeach; ?></tbody>
                    <tfoot><tr><th scope="row" colspan="4">Total</th><td class="is-num"><strong><?= $this->e($brl((int)$doc['total_cents'])) ?></strong></td></tr></tfoot>
                </table></div>
            <?php endif; ?>
            <?php if ($upcoming !== [] && $doc['kind'] !== 'consent_letter'): ?>
                <div class="erp-table-wrap" role="region" aria-label="Contas a vencer" tabindex="0"><table class="erp-table">
                    <caption class="erp-unit-doc__caption">Contas a vencer em aberto (<?= $this->e($brl((int)($content['upcoming_cents'] ?? 0))) ?>)</caption>
                    <thead><tr><th>Conta</th><th>Competência</th><th>Vencimento</th><th class="is-num">Valor</th></tr></thead>
                    <tbody><?php foreach ($upcoming as $u): ?><tr><td><?= $this->e((string)$u['description']) ?></td><td><?= $this->e($month((string)$u['competence'])) ?></td><td><?= $this->e($date((string)$u['due_on'])) ?></td><td class="is-num"><?= $this->e($brl((int)$u['amount_cents'])) ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
            <?php // ADR 0115: assinatura do síndico e da administradora, local e data, antes do rodapé de verificação.
            $issuedOn = $date($doc['issued_at'] ?? $doc['created_at'] ?? $doc['as_of'] ?? null); ?>
            <section class="erp-unit-doc__sign" aria-label="Assinaturas" data-unit-document-signatures>
                <p class="erp-unit-doc__place"><?= $this->e((string)$doc['condominium_name']) ?>, <?= $this->e($issuedOn) ?>.</p>
                <div class="erp-unit-doc__sign-grid">
                    <div class="erp-unit-doc__sign-line"><span>Síndico(a)</span><small>Nome e assinatura</small></div>
                    <div class="erp-unit-doc__sign-line"><span>Connect · administradora de condomínios</span><small>Responsável pela emissão</small></div>
                </div>
            </section>
            <?php // ADR 0099 e 0115: código impresso e endereço fixo da verificação pública (o código não vai no link).
            // ADR 0160: a base do link vem só da configuração (CONF_URL_SSL), nunca do Host da requisição.
            $verification = \Source\Services\Erp\DocumentVerification::footer((string)($doc['verification_code'] ?? $doc['verification_display'] ?? ''), \Source\Services\Erp\DocumentVerification::configuredBase());
            $verificationQr = $verification !== null ? \Source\Support\QrCode::svg($verification['link'], 96, 'QR code para abrir a página de verificação') : null; ?>
            <footer class="erp-unit-doc__foot" data-unit-document-verification>
                <div><span class="erp-muted">Código de verificação</span><strong class="erp-unit-doc__code"><?= $this->e((string)$doc['verification_display']) ?></strong></div>
                <div class="erp-unit-doc__verify-text">
                    <?php if ($verification !== null): ?>
                        <p class="erp-unit-doc__verify">Para conferir a autenticidade, acesse <a href="<?= $this->e($verification['link']) ?>" rel="noreferrer"><?= $this->e($verification['address']) ?></a> e digite o código ao lado.</p>
                    <?php endif; ?>
                    <p class="erp-muted">Qualquer pessoa pode conferir, sem login. Documento emitido a partir dos registros da administradora na data de referência; pagamentos posteriores não aparecem.</p>
                </div>
                <?php if ($verificationQr !== null): ?><span class="erp-unit-doc__qr"><?= $verificationQr ?></span><?php endif; ?>
            </footer>
        </article>
        <?php if ($canManageDocuments && !$cancelled): ?>
            <div class="erp-form__actions erp-unit-doc__actions"><button type="button" class="erp-btn erp-btn--danger" data-unit-document-cancel="<?= (int)$doc['id'] ?>" data-label="<?= $this->e($doc['kind_label'] . ' nº ' . (int)$doc['id'] . ' · ' . $doc['unit_label']) ?>">Cancelar documento</button></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="erp-grid erp-grid--2">
            <?php if ($canManageDocuments): ?>
                <<?= $receipt ? 'section' : 'form' ?> class="erp-card erp-form erp-unit-doc__panel"<?= $receipt ? '' : ' data-unit-document-form' ?> aria-labelledby="unit-doc-issue">
                    <div class="erp-card__head"><div><h2 id="unit-doc-issue">Emitir</h2></div></div>
                    <?php if ($documentUnits === []): ?>
                        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('file') ?></span><strong>Nenhuma unidade ativa</strong><p>Cadastre as unidades em Condomínios › Unidades ou escolha outro condomínio na barra superior.</p></div>
                    <?php elseif ($receipt): ?>
                        <?php $this->insert('components/cards/receipt-entries', ['documentUnits' => $documentUnits, 'receiptUnit' => $receiptUnit, 'receiptEntries' => $receiptEntries, 'route' => $route, 'brl' => $brl, 'date' => $date, 'month' => $month]); ?>
                    <?php else: ?>
                        <div class="erp-form__grid">
                            <div class="erp-field erp-col-12"><label for="unit-doc-unit">Unidade</label><select class="erp-select" id="unit-doc-unit" name="unit_id" required><option value="">Selecione</option><?php $group = null; foreach ($documentUnits as $u): if ($group !== $u['condominium_name']): if ($group !== null): ?></optgroup><?php endif; $group = $u['condominium_name']; ?><optgroup label="<?= $this->e($group) ?>"><?php endif; ?><option value="<?= (int)$u['id'] ?>"><?= $this->e($u['label']) ?></option><?php endforeach; ?></optgroup></select></div>
                            <div class="erp-field erp-col-6"><label for="unit-doc-asof">Data de referência</label><input class="erp-input" type="date" id="unit-doc-asof" name="as_of" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                            <?php if ($documentKind === 'quittance'): ?>
                                <div class="erp-field erp-col-6"><label for="unit-doc-until">Competências até</label><input class="erp-input" type="month" id="unit-doc-until" name="until_competence" value="<?= date('Y-m', strtotime('first day of last month')) ?>" max="<?= date('Y-m') ?>" required></div>
                            <?php elseif ($documentKind === 'consent_letter'): ?>
                                <div class="erp-field erp-col-6"><label for="unit-doc-purpose">Finalidade</label><input class="erp-input" id="unit-doc-purpose" name="purpose" minlength="5" maxlength="40" required placeholder="Ex.: venda da unidade"></div>
                            <?php endif; ?>
                        </div>
                        <p class="erp-muted">O documento é gerado das contas a receber da unidade e fica gravado com um código de verificação. Depois de emitido, não muda; para corrigir, cancele e emita outro.</p>
                        <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('file', 'erp-icon--sm') ?>Emitir documento</button></div>
                    <?php endif; ?>
                </<?= $receipt ? 'section' : 'form' ?>>
            <?php endif; ?>
            <form class="erp-card erp-form erp-unit-doc__panel" data-unit-document-verify aria-labelledby="unit-doc-verify">
                <div class="erp-card__head"><div><h2 id="unit-doc-verify">Conferir autenticidade</h2><p>Digite o código impresso no documento para abrir o original.</p></div></div>
                <div class="erp-field erp-unit-doc__verify-field"><label for="unit-doc-code">Código de verificação</label><input class="erp-input erp-unit-doc__code-input" id="unit-doc-code" name="code" required autocomplete="off" spellcheck="false" placeholder="ABCD-EFGH-JKMN-PQRS" pattern="^[A-Za-z2-9\s\-]{16,23}$" maxlength="23"></div>
                <div class="erp-form__actions"><button type="submit" class="erp-btn"><?= $icon('check', 'erp-icon--sm') ?>Conferir</button></div>
            </form>
        </div>

        <section class="erp-card" aria-labelledby="unit-doc-list">
            <div class="erp-card__head"><div><h2 id="unit-doc-list">Emitidos</h2><p><?= (int)($unitDocuments['total'] ?? 0) ?> documento(s) deste tipo no escopo.</p></div></div>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('file') ?></span><strong>Nenhum documento emitido</strong><p><?= $canManageDocuments ? 'Escolha a unidade acima e emita o primeiro.' : 'Os documentos emitidos aparecem aqui.' ?></p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="unit-doc-list" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Unidade</th><th scope="col">Referência</th><th scope="col">Resultado</th><th scope="col" class="is-num">Total</th><th scope="col">Código</th><th scope="col">Situação</th><th scope="col" class="is-num">Ações</th></tr></thead>
                    <tbody><?php foreach ($rows as $r): $active = $r['status'] === 'issued'; ?><tr>
                        <td><strong class="erp-nowrap"><?= $this->e((string)$r['unit_label']) ?></strong><br><span class="erp-muted"><?= $this->e((string)$r['condominium_name']) ?></span></td>
                        <td><?= $this->e($date($r['as_of'])) ?><?php if ($r['reference'] !== null && $r['reference'] !== ''): ?><br><span class="erp-muted"><?= $this->e($r['kind'] === 'quittance' ? 'até ' . $month((string)$r['reference']) : (string)$r['reference']) ?></span><?php endif; ?></td>
                        <td><?= $r['result'] === 'clear' ? '<span class="erp-badge erp-badge--success">' . ($r['kind'] === 'receipt' ? 'Pago' : ($r['kind'] === 'quittance' ? 'Quitado' : 'Nada consta')) . '</span>' : '<span class="erp-badge erp-badge--warning">Com débitos</span>' ?></td>
                        <td class="is-num"><?= $this->e($brl((int)$r['total_cents'])) ?></td>
                        <td><code class="erp-unit-doc__code-sm"><?= $this->e((string)$r['verification_display']) ?></code><br><span class="erp-muted">Nº <?= (int)$r['id'] ?></span></td>
                        <td><?= $active ? '<span class="erp-badge erp-badge--info">Emitido</span>' : '<span class="erp-badge erp-badge--danger">Cancelado</span>' ?></td>
                        <td class="is-num"><div class="erp-table__actions"><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?documento=' . (int)$r['id']) ?>"><?= $icon('printer', 'erp-icon--sm') ?>Abrir</a><?php if ($canManageDocuments && $active): ?><button type="button" class="erp-btn erp-btn--sm" data-unit-document-cancel="<?= (int)$r['id'] ?>" data-label="<?= $this->e($r['kind_label'] . ' nº ' . (int)$r['id'] . ' · ' . $r['unit_label']) ?>">Cancelar</button><?php endif; ?></div></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
                <?php if (($unitDocuments['pages'] ?? 0) > 1): ?>
                    <nav class="erp-pagination" aria-label="Paginação">
                        <span>Página <?= (int)$unitDocuments['page'] ?> de <?= (int)$unitDocuments['pages'] ?></span>
                        <div class="erp-pagination__pages">
                            <?php if ($unitDocuments['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?pagina=' . ($unitDocuments['page'] - 1)) ?>" rel="prev">Anterior</a><?php endif; ?>
                            <?php if ($unitDocuments['page'] < $unitDocuments['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?pagina=' . ($unitDocuments['page'] + 1)) ?>" rel="next">Próxima</a><?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canManageDocuments): ?>
    <dialog class="erp-modal" id="unit-document-cancel" aria-labelledby="unit-document-cancel-title">
        <form data-unit-document-cancel-form>
            <div class="erp-modal__head"><h2 id="unit-document-cancel-title">Cancelar documento</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p data-unit-document-cancel-label></p>
                <p class="erp-muted">O documento continua gravado e o código passa a mostrar que foi cancelado. Para corrigir, emita outro.</p>
                <div class="erp-field"><label for="unit-doc-cancel-reason">Motivo do cancelamento</label><textarea class="erp-textarea" id="unit-doc-cancel-reason" name="reason" minlength="5" maxlength="255" required placeholder="Ex.: data de referência errada"></textarea></div>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--danger">Cancelar documento</button></div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
