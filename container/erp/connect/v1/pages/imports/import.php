<?php
/**
 * Importações (AP-140 extrato OFX, AP-141 NF-e em XML, AP-142 a AP-146 planilhas e AP-044 leituras; ADR 0058, 0074, 0086 e 0079) com
 * dados reais. Enviar o arquivo gera a prévia pela API (POST /api/v1/imports/preview, multipart), que
 * a tela mostra linha a linha com os erros; "Aplicar" chama POST /api/v1/imports/{id}/apply, que
 * valida o arquivo inteiro de novo. No extrato, a fila de linhas pendentes se resolve à mão
 * (?linha=ID: ligar a um lançamento pago ou ignorar, com nota). erp.js: data-erp-imports.
 * @var array $currentPage @var string $importKind @var ?int $importContext @var array $imports @var ?array $importDetail
 * @var ?string $importError @var list<array> $importBankAccounts @var list<array> $importExpenseAccounts @var ?array $pendingLines @var ?array $lineDetail
 * @var bool $canImport @var bool $canResolveLines
 */
use Source\Controllers\Erp\Connect\ImportScreens;
use Source\Services\Erp\Imports\Imports;

$this->layout('default');
$route = erp_url($currentPage['route']);
$definition = Imports::KINDS[$importKind];
$ofx = $importKind === 'ofx';
$nfe = $importKind === 'nfe';
$maxBytes = ImportScreens::maxBytes($importKind);
$importExpenseAccounts ??= [];
$brl = static fn (int $cents): string => ($cents < 0 ? '− ' : '') . 'R$ ' . number_format(abs($cents) / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$stamp = static fn (?string $value): string => $value ? date('d/m/Y H:i', (int)strtotime($value)) : '—';
$rows = $imports['data'] ?? [];
$accept = implode(',', array_map(static fn (string $f): string => '.' . $f, $definition['formats']));
$header = ImportScreens::templateHeader($importKind);
$lineStatus = ['matched' => ['Conferida', 'success'], 'unmatched' => ['Pendente', 'warning'], 'ignored' => ['Ignorada', 'plain']];
$leads = [
    'nfe' => 'Nota fiscal eletrônica (XML) de um fornecedor já cadastrado, emitida para o CNPJ do condomínio. Cada duplicata da nota vira uma conta a pagar na conta de despesa escolhida. A mesma nota não entra duas vezes. A assinatura digital não é conferida na SEFAZ.',
    'ofx' => 'Extrato da conta bancária em OFX. Cada linha procura o lançamento pago ou a transferência da mesma conta, com o mesmo valor e até 3 dias de diferença. O extrato não cria nem baixa lançamento: o que não bate fica pendente para você resolver.',
    'entries' => 'Receitas e despesas em planilha CSV ou XLSX. A prévia valida o arquivo inteiro; só com todas as linhas certas dá para aplicar.',
    'unit_entries' => 'Receitas de cada unidade em planilha CSV ou XLSX, com a unidade em todas as linhas.',
    'payables' => 'Despesas a pagar em planilha CSV ou XLSX, com o fornecedor opcional (pelo CNPJ ou CPF).',
    'fractions' => 'Fração ideal de cada unidade. A planilha precisa trazer todas as unidades ativas, sem repetir, com soma 1 (100%).',
    'delinquents' => 'Carga do histórico de débitos: receitas em aberto das unidades, com vencimento no passado.',
    'readings' => 'Leituras de água, gás ou energia por relógio e competência. Leitura já lançada da mesma competência é substituída, se ainda não foi cobrada.',
];
$statusLabel = static fn (array $r): array => $r['status'] === 'applied' ? ['Aplicada', 'success'] : ($r['error_count'] > 0 ? ['Prévia com erros', 'danger'] : ['Em prévia', 'info']);

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $importDetail !== null ? 'Importação nº ' . (int)$importDetail['id'] : $currentPage['name'],
    'pageLead' => $importDetail !== null ? $importDetail['kind_label'] . ' · ' . $importDetail['original_name'] : ($leads[$importKind] ?? ''),
    'pageActions' => $importDetail !== null ? '<a class="erp-btn" href="' . $this->e($route) . '">Voltar</a>' : '',
]);
?>
<div class="erp-stack" data-erp-imports data-kind="<?= $this->e($importKind) ?>" data-route="<?= $this->e($route) ?>" data-preview-api="<?= $this->e(url('/api/v1/imports/preview')) ?>" data-apply-api="<?= $this->e(url('/api/v1/imports/__ID__/apply')) ?>">
    <?= csrf_input() ?>
    <?php if ($importError !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($importError) ?></strong><a href="<?= $this->e($route) ?>">Voltar à lista</a></div></div>
    <?php endif; ?>

    <?php if ($importDetail !== null): $d = $importDetail; $s = $d['summary'] ?? []; [$label, $tone] = $statusLabel($d); ?>
        <section class="erp-card" aria-labelledby="import-detail-title">
            <div class="erp-card__head"><div><h2 id="import-detail-title">Resumo</h2><p>Enviada em <?= $this->e($stamp($d['created_at'])) ?><?= $d['applied_at'] ? ' · aplicada em ' . $this->e($stamp($d['applied_at'])) : '' ?></p></div><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span></div>
            <dl class="erp-import-facts">
                <div><dt>Linhas</dt><dd><?= (int)$d['rows_count'] ?></dd></div>
                <div><dt>Com erro</dt><dd><?= (int)$d['error_count'] ?></dd></div>
                <?php if (isset($s['total_cents'])): ?><div><dt>Total</dt><dd><?= $this->e($brl((int)$s['total_cents'])) ?></dd></div><?php endif; ?>
                <?php if (isset($s['fraction_sum'])): ?><div><dt>Soma das frações</dt><dd><?= $this->e(number_format((float)$s['fraction_sum'] * 100, 4, ',', '.')) ?>%</dd></div><?php endif; ?>
                <?php if (isset($s['consumption'])): ?><div><dt>Consumo</dt><dd><?= $this->e(number_format((float)$s['consumption'], 3, ',', '.')) ?></dd></div><?php endif; ?>
                <?php if ($ofx): ?>
                    <div><dt>Período</dt><dd><?= $this->e($date($s['from'] ?? null)) ?> a <?= $this->e($date($s['to'] ?? null)) ?></dd></div>
                    <div><dt>Entradas</dt><dd><?= $this->e($brl((int)($s['credits_cents'] ?? 0))) ?></dd></div>
                    <div><dt>Saídas</dt><dd><?= $this->e($brl(-(int)($s['debits_cents'] ?? 0))) ?></dd></div>
                <?php endif; ?>
                <?php if (isset($s['applied']) && is_array($s['applied'])): ?><div><dt>Gravado</dt><dd><?= $this->e(implode(' · ', array_map(static fn ($k, $v): string => ImportScreens::appliedLabel((string)$k) . ': ' . (is_int($v) && str_ends_with((string)$k, '_cents') ? 'R$ ' . number_format($v / 100, 2, ',', '.') : (string)$v), array_keys($s['applied']), $s['applied']))) ?></dd></div><?php endif; ?>
            </dl>
            <?php $check = $s['balance_check'] ?? null; if ($ofx && is_array($check) && $check['matches'] !== null): ?>
                <div class="erp-alert<?= $check['matches'] ? ' erp-alert--success' : ' erp-alert--warning' ?>" role="note"><?= $icon($check['matches'] ? 'check' : 'alert') ?><div><strong><?= $check['matches'] ? 'O saldo do banco confere com o do sistema.' : 'O saldo do banco não confere com o do sistema.' ?></strong>Banco <?= $this->e($brl((int)$check['bank_cents'])) ?> · sistema <?= $this->e($brl((int)$check['system_cents'])) ?><?= $check['matches'] ? '' : ' · diferença ' . $this->e($brl((int)$check['difference_cents'])) . '. Confira lançamentos pagos e transferências que faltam.' ?></div></div>
            <?php endif; ?>
            <?php if ($d['status'] === 'previewed'): ?>
                <p class="erp-field__hint">A prévia não fica guardada linha a linha. Para ver os erros de novo, envie o mesmo arquivo: ele volta para esta importação.</p>
                <?php if ($canImport && (int)$d['error_count'] === 0): ?><div class="erp-form__actions"><button type="button" class="erp-btn erp-btn--primary" data-import-apply="<?= (int)$d['id'] ?>" data-label="<?= $this->e($d['original_name']) ?>"><?= $icon('check', 'erp-icon--sm') ?>Aplicar importação</button></div><?php endif; ?>
            <?php endif; ?>
        </section>
        <?php if ($ofx && ($d['lines'] ?? []) !== []): ?>
            <section class="erp-card" aria-labelledby="import-lines-title">
                <div class="erp-card__head"><div><h2 id="import-lines-title">Linhas do extrato</h2><p><?= count($d['lines']) ?> linha(s) gravada(s).</p></div></div>
                <div class="erp-table-wrap" role="region" aria-labelledby="import-lines-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Data</th><th scope="col">Histórico</th><th scope="col" class="is-num">Valor</th><th scope="col">Situação</th></tr></thead>
                    <tbody><?php foreach ($d['lines'] as $l): [$ll, $lt] = $lineStatus[$l['status']] ?? [$l['status'], 'plain']; ?><tr>
                        <td class="erp-nowrap"><?= $this->e($date($l['posted_on'])) ?></td>
                        <td><?= $this->e((string)($l['memo'] ?? '—')) ?><br><span class="erp-muted">FITID <?= $this->e($l['fitid']) ?></span></td>
                        <td class="is-num erp-nowrap<?= $l['amount_cents'] < 0 ? ' erp-panel-negative' : '' ?>"><?= $this->e($brl((int)$l['amount_cents'])) ?></td>
                        <td><span class="erp-badge erp-badge--<?= $lt ?>"><?= $ll ?></span><?php if ($l['status'] === 'unmatched'): ?><br><a href="<?= $this->e($route . '?linha=' . (int)$l['id']) ?>#conciliar">Resolver</a><?php elseif ($l['note']): ?><br><span class="erp-muted"><?= $this->e((string)$l['note']) ?></span><?php endif; ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($canImport): ?>
            <?php if ($importContext === null): ?>
                <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Escolha um condomínio na barra superior para importar.</strong>A lista abaixo mostra todos os condomínios do seu escopo.</div></div>
            <?php elseif ($nfe && $importExpenseAccounts === []): ?>
                <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><strong>O condomínio não tem conta de despesa analítica ativa.</strong>Cadastre a conta em Cadastros › Plano de contas.</div></div>
            <?php elseif ($ofx && $importBankAccounts === []): ?>
                <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><strong>O condomínio não tem conta bancária ativa.</strong>Cadastre a conta em Financeiro › Contas bancárias.</div></div>
            <?php else: ?>
                <form class="erp-card erp-form" data-import-form data-condominium="<?= (int)$importContext ?>" aria-labelledby="import-form-title" novalidate>
                    <div class="erp-card__head"><div><h2 id="import-form-title">Enviar arquivo</h2><p><?= $this->e(strtoupper(implode(' ou ', $definition['formats']))) ?>, até <?= $this->e(number_format($maxBytes / 1_000_000, 0)) ?> MB<?= $ofx || $nfe ? '' : ' e 5.000 linhas' ?>. Nada é gravado antes de você conferir a prévia e aplicar.</p></div></div>
                    <div class="erp-form__grid">
                        <?php if ($ofx): ?>
                            <div class="erp-field erp-col-6"><label for="import-bank">Conta bancária do extrato</label><select class="erp-select" id="import-bank" name="bank_account_id" required><option value="">Selecione</option><?php foreach ($importBankAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select></div>
                        <?php endif; ?>
                        <?php if ($nfe): ?>
                            <div class="erp-field erp-col-6"><label for="import-expense">Conta de despesa</label><select class="erp-select" id="import-expense" name="chart_account_id" required aria-describedby="import-expense-hint"><option value="">Selecione</option><?php foreach ($importExpenseAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select><small class="erp-muted" id="import-expense-hint">As contas a pagar das duplicatas entram nesta conta.</small></div>
                            <div class="erp-field erp-col-6"><label for="import-due">Vencimento (só para nota sem duplicata)</label><input class="erp-input" type="date" id="import-due" name="due_on" aria-describedby="import-due-hint"><small class="erp-muted" id="import-due-hint">Com duplicatas no XML, vale o vencimento de cada uma.</small></div>
                        <?php endif; ?>
                        <div class="erp-field erp-col-6"><label for="import-file">Arquivo</label><input class="erp-input" type="file" id="import-file" name="file" accept="<?= $this->e($accept) ?>" data-max-bytes="<?= $maxBytes ?>" required aria-describedby="import-file-hint"><small class="erp-muted" id="import-file-hint"><?= $ofx ? 'O número da conta no OFX precisa ser o da conta escolhida.' : ($nfe ? 'O XML da NF-e (modelo 55) que o fornecedor enviou.' : 'Primeira linha com os nomes das colunas.') ?></small></div>
                    </div>
                    <?php if ($header !== null): ?>
                        <div class="erp-import-columns">
                            <p><strong>Colunas obrigatórias:</strong> <?php foreach ($definition['required'] as $c): ?><code><?= $this->e($c) ?></code> <?php endforeach; ?></p>
                            <?php if ($definition['optional'] !== []): ?><p><strong>Opcionais:</strong> <?php foreach ($definition['optional'] as $c): ?><code><?= $this->e($c) ?></code> <?php endforeach; ?></p><?php endif; ?>
                            <p class="erp-muted">Valores em reais (1.234,56), datas DD/MM/AAAA e competência MM/AAAA.<?= in_array($importKind, ['entries', 'unit_entries', 'payables', 'delinquents'], true) ? ' "conta" é o código de uma conta analítica do plano de contas.' : '' ?> <a href="data:text/csv;charset=utf-8,<?= rawurlencode("\u{FEFF}" . $header . "\r\n") ?>" download="modelo-<?= $this->e($importKind) ?>.csv">Baixar modelo CSV</a></p>
                        </div>
                    <?php endif; ?>
                    <div class="erp-progress" data-import-progress hidden role="progressbar" aria-label="Envio do arquivo" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-import-progress-bar style="width:0%"></span></div>
                    <div data-form-error aria-live="assertive"></div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('upload', 'erp-icon--sm') ?>Enviar e ver a prévia</button></div>
                </form>
                <section class="erp-card" data-import-preview aria-labelledby="import-preview-title" tabindex="-1" hidden>
                    <div class="erp-card__head"><div><h2 id="import-preview-title">Prévia</h2><p data-import-preview-summary></p></div></div>
                    <div data-import-preview-alert></div>
                    <div class="erp-import-preview-filter" data-import-preview-filter hidden><label class="erp-check"><input type="checkbox" data-import-only-errors> Mostrar só as linhas com erro</label></div>
                    <div class="erp-table-wrap" role="region" aria-labelledby="import-preview-title" tabindex="0"><table class="erp-table">
                        <thead><tr><th scope="col" class="is-num">Linha</th><th scope="col">Situação</th><th scope="col">Conteúdo</th><th scope="col">Erros</th></tr></thead>
                        <tbody data-import-preview-rows></tbody>
                    </table></div>
                    <div class="erp-form__actions"><button type="button" class="erp-btn" data-import-reset>Enviar outro arquivo</button><button type="button" class="erp-btn erp-btn--primary" data-import-apply hidden><?= $icon('check', 'erp-icon--sm') ?>Aplicar importação</button></div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($ofx && $pendingLines !== null): $pending = $pendingLines['data'] ?? []; ?>
            <div class="erp-grid<?= $lineDetail !== null ? ' erp-grid--main' : '' ?>">
                <section class="erp-card" aria-labelledby="pending-lines-title">
                    <div class="erp-card__head"><div><h2 id="pending-lines-title">Linhas pendentes</h2><p><?= (int)($pendingLines['total'] ?? 0) ?> linha(s) do extrato sem lançamento ou com mais de um candidato<?= $importContext === null ? ', em todos os condomínios' : '' ?>.</p></div></div>
                    <?php if ($pending === []): ?>
                        <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('check') ?></span><strong>Nenhuma linha pendente</strong><p>As linhas que o extrato não conseguir conferir sozinho aparecem aqui.</p></div>
                    <?php else: ?>
                        <div class="erp-table-wrap" role="region" aria-labelledby="pending-lines-title" tabindex="0"><table class="erp-table">
                            <thead><tr><th scope="col">Data</th><th scope="col">Histórico</th><th scope="col" class="is-num">Valor</th><th scope="col" class="is-num">Ações</th></tr></thead>
                            <tbody><?php foreach ($pending as $l): $lid = (int)$l['id']; $selected = ($lineDetail['line']['id'] ?? null) === $lid; ?><tr<?= $selected ? ' class="is-selected"' : '' ?>>
                                <td class="erp-nowrap"><?= $this->e($date($l['posted_on'])) ?></td>
                                <td><?= $this->e((string)($l['memo'] ?? '—')) ?><br><span class="erp-muted"><?= $this->e($l['bank_account']) ?></span></td>
                                <td class="is-num erp-nowrap<?= $l['amount_cents'] < 0 ? ' erp-panel-negative' : '' ?>"><?= $this->e($brl((int)$l['amount_cents'])) ?></td>
                                <td class="is-num"><a class="erp-btn erp-btn--sm<?= $selected ? '' : ' erp-btn--primary' ?>" href="<?= $this->e($route . '?linha=' . $lid) ?>#conciliar" aria-label="Resolver a linha de <?= $this->e($date($l['posted_on']) . ', ' . $brl((int)$l['amount_cents'])) ?>"<?= $selected ? ' aria-current="true"' : '' ?>>Resolver</a></td>
                            </tr><?php endforeach; ?></tbody>
                        </table></div>
                    <?php endif; ?>
                </section>
                <?php if ($lineDetail !== null): $line = $lineDetail['line']; $candidates = $lineDetail['candidates']; ?>
                    <aside class="erp-card" id="conciliar" tabindex="-1" aria-labelledby="line-title">
                        <div class="erp-card__head"><div><h2 id="line-title">Resolver linha</h2><p><?= $this->e($date($line['posted_on'])) ?> · <?= $this->e($brl($line['amount_cents'])) ?> · <?= $this->e($line['bank_account']) ?></p></div></div>
                        <p><?= $this->e((string)($line['memo'] ?? 'Sem histórico')) ?></p>
                        <?php if ($line['status'] !== 'unmatched'): ?>
                            <p class="erp-field__hint">Esta linha já foi resolvida.</p>
                        <?php elseif (!$canResolveLines): ?>
                            <p class="erp-field__hint">Sua conta pode ver, mas não resolver, as linhas do extrato.</p>
                        <?php else: ?>
                            <form data-line-form data-api="<?= $this->e(url('/api/v1/bank-statement-lines/' . (int)$line['id'] . '/resolve')) ?>" novalidate>
                                <fieldset class="erp-line-options">
                                    <legend><?= $candidates === [] ? 'Nenhum lançamento pago desta conta com o mesmo valor e sentido.' : count($candidates) . ' lançamento(s) pago(s) com o mesmo valor e sentido' ?></legend>
                                    <?php foreach ($candidates as $i => $c): ?>
                                        <label class="erp-check erp-line-option"><input type="radio" name="choice" value="<?= (int)$c['id'] ?>"<?= $i === 0 && count($candidates) === 1 ? ' checked' : '' ?>><span><strong><?= $this->e($c['description']) ?></strong><small class="erp-muted">pago em <?= $this->e($date($c['paid_on'])) ?><?= $c['days'] > 0 ? ' (' . (int)$c['days'] . ' dia(s) de diferença)' : ' (mesmo dia)' ?><?= $c['unit_label'] ? ' · ' . $this->e((string)$c['unit_label']) : '' ?><?= $c['document_number'] ? ' · ' . $this->e((string)$c['document_number']) : '' ?></small></span></label>
                                    <?php endforeach; ?>
                                    <label class="erp-check erp-line-option"><input type="radio" name="choice" value="ignore"<?= $candidates === [] ? ' checked' : '' ?>><span><strong>Ignorar esta linha</strong><small class="erp-muted">Por exemplo, tarifa já lançada de outro jeito. A linha sai da fila com a nota.</small></span></label>
                                </fieldset>
                                <?php if ($candidates === []): ?><p class="erp-field__hint">Se falta o lançamento, lance a conta paga nesta conta bancária e volte aqui.</p><?php endif; ?>
                                <div class="erp-field"><label for="line-note">Nota</label><textarea class="erp-textarea" id="line-note" name="note" minlength="5" maxlength="255" required placeholder="Ex.: conferido com o comprovante"></textarea></div>
                                <div data-form-error aria-live="assertive"></div>
                                <div class="erp-form__actions"><a class="erp-btn" href="<?= $this->e($route) ?>">Fechar</a><button type="submit" class="erp-btn erp-btn--primary">Resolver</button></div>
                            </form>
                        <?php endif; ?>
                    </aside>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="erp-card" aria-labelledby="import-list-title">
            <div class="erp-card__head"><div><h2 id="import-list-title">Importações enviadas</h2><p><?= (int)($imports['total'] ?? 0) ?> importação(ões)<?= $importContext === null ? ' em todos os condomínios' : '' ?>.</p></div></div>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('upload') ?></span><strong>Nenhuma importação</strong><p><?= $canImport && $importContext !== null ? 'Envie o primeiro arquivo acima.' : 'As importações enviadas aparecem aqui.' ?></p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="import-list-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Enviada</th><th scope="col">Arquivo</th><th scope="col" class="is-num">Linhas</th><th scope="col">Situação</th><th scope="col" class="is-num">Ações</th></tr></thead>
                    <tbody><?php foreach ($rows as $r): [$label, $tone] = $statusLabel($r); ?><tr>
                        <td class="erp-nowrap"><?= $this->e($stamp($r['created_at'])) ?></td>
                        <td><strong><?= $this->e($r['original_name']) ?></strong> <span class="erp-muted">nº <?= (int)$r['id'] ?></span><?= $importContext === null ? '<br><span class="erp-muted">' . $this->e((string)$r['condominium_name']) . '</span>' : '' ?></td>
                        <td class="is-num"><?= (int)$r['rows_count'] ?><?= $r['error_count'] > 0 ? '<br><span class="erp-muted">' . (int)$r['error_count'] . ' com erro</span>' : '' ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span></td>
                        <td class="is-num"><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?importacao=' . (int)$r['id']) ?>" aria-label="Ver a importação nº <?= (int)$r['id'] ?>, <?= $this->e($r['original_name']) ?>">Ver</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
