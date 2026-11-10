<?php
/**
 * Remessas (AP-037, ADR 0156) com dados reais: boletos enviados ao banco agrupados por conta bancária e dia
 * ($bankRemittances, de Panel::bankingData; a mesma lista está em GET /api/v1/bank-remittances). Com o Inter o
 * registro é online, sem arquivo de remessa: a tela mostra o envio e a confirmação do banco. Só leitura.
 * @var array $currentPage @var array $bankRemittances @var list<array> $condoOptions
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$filters = [
    'de' => is_string($_GET['de'] ?? null) ? $_GET['de'] : '',
    'ate' => is_string($_GET['ate'] ?? null) ? $_GET['ate'] : '',
    'situacao' => is_string($_GET['situacao'] ?? null) ? $_GET['situacao'] : '',
];
$query = static function (array $changes) use ($filters): string {
    $params = array_filter(array_merge($filters, $changes), static fn ($v): bool => $v !== null && $v !== '');
    return $params === [] ? '' : '?' . http_build_query($params);
};
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$tones = ['confirmed' => 'success', 'sent' => 'warning', 'rejected' => 'danger'];
$statuses = \Source\Services\Erp\Banking\BankRemittances::STATUSES;
$context = \Source\Support\Erp\CondominiumContext::id();
$rows = $bankRemittances['data'] ?? [];
$chargesRoute = erp_url($catalog['pages']['AP-035']['route'] ?? '/cobranca/boletos');

ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e($chargesRoute) ?>"><?= $icon('receipt', 'erp-icon--sm') ?>Ver boletos</a>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Boletos enviados ao banco, agrupados por conta bancária e dia, com a confirmação do registro.',
    'pageActions' => $actions,
]);
?>
<div class="erp-alert erp-alert--info" role="note"><div><strong>Sem arquivo de remessa no Inter.</strong> O Banco Inter registra cada boleto na hora, pela API. Esta tela mostra os lotes enviados e o que o banco já confirmou. Bancos que usam arquivo CNAB entram aqui quando forem integrados.</div></div>
<div class="erp-stack">
    <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Filtrar remessas">
        <div class="erp-field erp-field--compact"><label for="rem-from">De</label><input class="erp-input" type="date" id="rem-from" name="de" value="<?= $this->e($filters['de']) ?>"></div>
        <div class="erp-field erp-field--compact"><label for="rem-to">Até</label><input class="erp-input" type="date" id="rem-to" name="ate" value="<?= $this->e($filters['ate']) ?>"></div>
        <div class="erp-field erp-field--compact"><label for="rem-status">Situação</label><select class="erp-select" id="rem-status" name="situacao"><option value="">Todas</option>
            <?php foreach ($statuses as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $filters['situacao'] === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
        </select></div>
        <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button></div>
        <?php if (array_filter($filters) !== []): ?><div class="erp-field erp-field--actions"><a class="erp-btn erp-btn--ghost" href="<?= $this->e($route) ?>">Limpar filtros</a></div><?php endif; ?>
    </form>

    <section class="erp-card" aria-labelledby="rem-list">
        <div class="erp-card__head"><div><h2 id="rem-list">Remessas</h2><p><?= (int)($bankRemittances['total'] ?? 0) ?> lote(s)<?= $context === null ? ' em todos os condomínios do seu escopo' : '' ?></p></div></div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('upload') ?></span><strong>Nenhuma remessa encontrada</strong><p>Os lotes aparecem aqui quando boletos são emitidos em Boletos.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="rem-list" tabindex="0"><table class="erp-table">
                <thead><tr><th>Enviada em</th><th>Conta bancária</th><th>Forma de envio</th><th class="is-num">Boletos</th><th class="is-num">Confirmados</th><th class="is-num">Valor</th><th>Situação</th></tr></thead>
                <tbody><?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $this->e($date($row['sent_on'])) ?></td>
                        <td><strong><?= $this->e((string)$row['bank_account_name']) ?></strong><?php if ($context === null): ?><small class="erp-muted"><?= $this->e((string)$row['condominium_name']) ?></small><?php endif; ?></td>
                        <td><?= $this->e((string)$row['mode']) ?></td>
                        <td class="is-num"><?= (int)$row['charges_count'] ?></td>
                        <td class="is-num"><?= (int)$row['confirmed_count'] ?><?php if ((int)$row['pending_count'] > 0): ?><small class="erp-muted"><?= (int)$row['pending_count'] ?> aguardando</small><?php endif; ?><?php if ((int)$row['rejected_count'] > 0): ?><small class="erp-muted"><?= (int)$row['rejected_count'] ?> recusado(s)</small><?php endif; ?></td>
                        <td class="is-num"><?= $brl((int)$row['total_cents']) ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tones[$row['status']] ?? 'plain' ?>"><?= $this->e((string)$row['status_label']) ?></span></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if (($bankRemittances['pages'] ?? 0) > 1): ?>
                <nav class="erp-pagination" aria-label="Paginação">
                    <span>Página <?= (int)$bankRemittances['page'] ?> de <?= (int)$bankRemittances['pages'] ?></span>
                    <div class="erp-pagination__pages">
                        <?php if ($bankRemittances['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $bankRemittances['page'] - 1])) ?>" rel="prev">Anterior</a><?php endif; ?>
                        <?php if ($bankRemittances['page'] < $bankRemittances['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $bankRemittances['page'] + 1])) ?>" rel="next">Próxima</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
