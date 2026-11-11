<?php
/**
 * Livro caixa mensal (CN-007, ADR 0174) do condomínio da barra superior: saldo anterior, cada pagamento e
 * recebimento do mês com o saldo corrido, os anexos (notas e comprovantes) numerados, a conferência com as contas
 * bancárias e as despesas sem comprovante. Baixa em PDF pronto ou em pacote (PDF + anexos).
 * @var array $currentPage @var string $cashMonth @var bool $cashNeedsCondo @var ?string $cashError @var ?array $cashBook @var bool $cashCanManage
 */
use Source\Controllers\Erp\Connect\CashBookScreens as Screens;
use Source\Controllers\Erp\Connect\ReportScreens as RS;
use Source\Services\Erp\CashBookDocument as Doc;

$this->layout('default');
$route = erp_url($currentPage['route']);
$b = $cashBook;
$query = '?mes=' . rawurlencode($cashMonth);

ob_start(); ?>
    <?php if ($b !== null): ?>
        <a class="erp-btn" href="<?= $this->e(erp_url(Screens::ROUTE . '/imprimir' . $query)) ?>" target="_blank" rel="noopener"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</a>
        <a class="erp-btn" href="<?= $this->e(erp_url(Screens::ROUTE . '/pacote' . $query)) ?>"><?= $icon('download', 'erp-icon--sm') ?>Pacote com anexos</a>
        <a class="erp-btn erp-btn--primary" href="<?= $this->e(erp_url(Screens::ROUTE . '/pdf' . $query)) ?>"><?= $icon('file', 'erp-icon--sm') ?>Baixar PDF</a>
    <?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Tudo o que entrou e saiu do caixa do condomínio no mês, com o saldo dia a dia e as notas e comprovantes anexados. Sai pronto em PDF para o síndico e o conselho.',
    'pageActions' => $actions,
]);
?>
<?php if ($cashNeedsCondo): ?>
    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Escolha um condomínio</strong><p>O livro caixa é de um condomínio só. Escolha o condomínio na barra superior.</p></div>
<?php else: ?>
<form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Mês do livro caixa">
    <div class="erp-field erp-field--compact erp-month"><label for="cb-month">Mês</label><input class="erp-input" type="month" id="cb-month" name="mes" value="<?= $this->e($cashMonth) ?>" min="2000-01" max="<?= date('Y-m') ?>" required></div>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Gerar</button></div>
</form>

<?php if ($cashError !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível montar o livro caixa</strong><?= $this->e($cashError) ?></div></div>
<?php elseif ($b !== null): $month = Doc::monthLabel($b['competence']); ?>
    <section class="erp-card" aria-labelledby="cb-title">
        <div class="erp-card__head"><div><h2 id="cb-title">Livro caixa de <?= $this->e($month) ?></h2><p><?= $this->e($b['condominium']['name']) ?> · <?= $this->e(RS::brDate($b['from'])) ?> a <?= $this->e(RS::brDate($b['to'])) ?> · regime de caixa</p></div>
            <span class="erp-badge erp-badge--<?= $b['closed'] ? 'success' : 'warning' ?>"><?= $b['closed'] ? 'Mês fechado' : 'Prévia (mês aberto)' ?></span></div>
        <?php if ($b['reconciled']): ?>
            <div class="erp-alert erp-alert--success" role="note"><?= $icon('check') ?><div>Conferido: o saldo final é igual à soma das contas bancárias.</div></div>
        <?php else: ?>
            <div class="erp-alert erp-alert--danger" role="note"><?= $icon('alert') ?><div><strong>Diferença de <?= $this->e(RS::brl((int)$b['difference_cents'])) ?> com as contas bancárias.</strong> Confira lançamentos pagos sem conta bancária.</div></div>
        <?php endif; ?>
        <?php if ($b['missing_proof'] !== []): ?>
            <div class="erp-alert erp-alert--warning" role="note"><?= $icon('info') ?><div><strong><?= count($b['missing_proof']) ?> despesa(s) paga(s) sem nota fiscal ou comprovante.</strong> Anexe na tela do lançamento para o livro sair completo.</div></div>
        <?php endif; ?>
    </section>

    <section class="erp-grid erp-grid--kpi" aria-label="Totais do mês">
        <?php foreach ([
            ['Saldo anterior', $b['opening_cents'], 'wallet', ''], ['Entradas', $b['in_cents'], 'arrow-down', ''], ['Saídas', $b['out_cents'], 'arrow-up', ''],
            ['Saldo final', $b['closing_cents'], 'scale', $b['closing_cents'] < 0 ? 'danger' : ''],
        ] as $i => [$label, $cents, $kpiIcon, $tone]): $this->insert('components/cards/kpi', ['kpi' => ['label' => $label,
            'value' => RS::brl((int)$cents), 'meta' => $month,
            'icon' => $kpiIcon, 'tone' => $tone, 'trend' => null], 'delay' => $i * 40]); endforeach; ?>
    </section>

    <section class="erp-card" aria-labelledby="cb-moves">
        <div class="erp-card__head"><div><h2 id="cb-moves">Movimento do mês</h2><p><?= count($b['lines']) ?> lançamento(s) pago(s) ou recebido(s)</p></div></div>
        <?php if ($b['lines'] === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('wallet') ?></span><strong>Nenhum movimento no mês</strong><p>Os lançamentos entram no livro quando têm a data de pagamento preenchida.</p></div>
        <?php else: ?>
        <div class="erp-table-wrap" role="region" aria-labelledby="cb-moves" tabindex="0"><table class="erp-table erp-report-table">
            <thead><tr><th scope="col">Nº</th><th scope="col">Data</th><th scope="col">Histórico</th><th scope="col">Documento</th><th scope="col">Anexos</th><th scope="col" class="is-num">Entrada</th><th scope="col" class="is-num">Saída</th><th scope="col" class="is-num">Saldo</th></tr></thead>
            <tbody>
                <tr class="erp-muted"><td></td><td class="erp-nowrap"><?= $this->e(RS::brDate($b['from'])) ?></td><td colspan="5">Saldo anterior</td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$b['opening_cents'])) ?></td></tr>
            <?php foreach ($b['lines'] as $line): ?>
                <tr>
                    <td><?= (int)$line['seq'] ?></td>
                    <td class="erp-nowrap"><?= $this->e(RS::brDate($line['paid_on'])) ?></td>
                    <td><a href="<?= $this->e(erp_url('/financeiro/lancamentos/' . (int)$line['entry_id'])) ?>"><?= $this->e($line['description']) ?></a><br><small class="erp-muted"><?= $this->e($line['account_code'] . ' ' . $line['account_name']) ?><?= $line['counterpart'] !== null ? ' · ' . $this->e($line['counterpart']) : '' ?></small></td>
                    <td><?= $this->e($line['document_number'] ?? '') ?></td>
                    <td class="erp-nowrap"><?php if ($line['annex_refs'] === []): ?><?php if ($line['nature'] === 'despesa'): ?><a class="erp-badge erp-badge--warning" href="<?= $this->e(erp_url('/financeiro/lancamentos/' . (int)$line['entry_id']) . '#anexos') ?>">sem comprovante</a><?php endif; ?><?php else: ?><?php foreach ($line['annex_refs'] as $ref): ?><span class="erp-badge"><?= $this->e($ref) ?></span> <?php endforeach; ?><?php endif; ?></td>
                    <td class="is-num erp-nowrap"><?= $line['in_cents'] > 0 ? $this->e(RS::brl((int)$line['in_cents'])) : '' ?></td>
                    <td class="is-num erp-nowrap"><?= $line['out_cents'] > 0 ? $this->e(RS::brl((int)$line['out_cents'])) : '' ?></td>
                    <td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$line['balance_cents'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="5">Totais do mês</td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$b['in_cents'])) ?></td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$b['out_cents'])) ?></td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$b['closing_cents'])) ?></td></tr></tfoot>
        </table></div>
        <?php endif; ?>
    </section>

    <?php if ($b['banks'] !== []): ?>
    <section class="erp-card" aria-labelledby="cb-banks">
        <div class="erp-card__head"><div><h2 id="cb-banks">Contas bancárias</h2><p>Saldo de cada conta no mês, com as transferências entre contas</p></div></div>
        <div class="erp-table-wrap" role="region" aria-labelledby="cb-banks" tabindex="0"><table class="erp-table erp-report-table">
            <thead><tr><th scope="col">Conta</th><th scope="col" class="is-num">Saldo anterior</th><th scope="col" class="is-num">Entradas</th><th scope="col" class="is-num">Saídas</th><th scope="col" class="is-num">Saldo final</th></tr></thead>
            <tbody><?php foreach ($b['banks'] as $bank): ?><tr><td><?= $this->e($bank['name']) ?></td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$bank['opening_cents'])) ?></td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$bank['received_cents'])) ?></td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$bank['paid_cents'])) ?></td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$bank['closing_cents'])) ?></td></tr><?php endforeach; ?></tbody>
            <tfoot><tr><td>Total</td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$b['opening_cents'])) ?></td><td></td><td></td><td class="is-num erp-nowrap"><?= $this->e(RS::brl((int)$b['banks_closing_cents'])) ?></td></tr></tfoot>
        </table></div>
    </section>
    <?php endif; ?>

    <?php if ($b['annexes'] !== []): ?>
    <section class="erp-card" aria-labelledby="cb-annexes">
        <div class="erp-card__head"><div><h2 id="cb-annexes">Anexos do mês</h2><p>Imagens entram dentro do PDF; arquivos em PDF ou XML vão no pacote com anexos</p></div></div>
        <div class="erp-table-wrap" role="region" aria-labelledby="cb-annexes" tabindex="0"><table class="erp-table">
            <thead><tr><th scope="col">Ref.</th><th scope="col">Lançamento</th><th scope="col">Tipo</th><th scope="col">Arquivo</th></tr></thead>
            <tbody><?php foreach ($b['annexes'] as $annex): ?><tr><td><span class="erp-badge"><?= $this->e($annex['ref']) ?></span></td><td>nº <?= (int)$annex['line'] ?></td><td><?= $this->e($annex['kind_label']) ?></td>
                <td><a href="<?= $this->e(erp_url('/financeiro/lancamentos/' . (int)$annex['entry_id'] . '/anexos/' . (int)$annex['id'])) ?>"><?= $this->e($annex['original_name']) ?></a></td></tr><?php endforeach; ?></tbody>
        </table></div>
    </section>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
