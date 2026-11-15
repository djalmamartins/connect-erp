<?php
/**
 * CND (revisão de 15/11, etapa 5): a Certidão de Débitos da unidade, a mesma do ERP, emitida pelo morador com data
 * de hoje e baixada em PDF. Qualquer pessoa confere pelo código em /verificar-certidao-debitos.
 *
 * @var array $link
 * @var list<array> $certificates ResidentCertificates::list()
 */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
?>
<header class="app-page-head"><span class="app-eyebrow"><?= $this->e($link['condominium_name']) ?></span><h1>CND</h1><p>Certidão de débitos da unidade <?= $this->e($link['unit_label']) ?>, para venda, aluguel ou financiamento.</p></header>
<section class="app-card">
    <h2>Emitir a certidão de hoje</h2>
    <p class="app-hint">Sem conta vencida, a certidão sai como <strong>nada consta</strong>. Com conta vencida, ela lista as contas com os encargos. O PDF tem um código para qualquer pessoa conferir a autenticidade.</p>
    <form method="post" action="<?= app_url('/cnd') ?>" class="app-form">
        <?= csrf_input() ?>
        <button class="app-button" type="submit">Emitir certidão</button>
    </form>
</section>
<section class="app-section">
    <h2>Certidões emitidas</h2>
    <?php if ($certificates === []): ?><?php $this->insert('partials/empty', ['icon' => 'shield', 'text' => 'Nenhuma certidão emitida nos últimos 12 meses.']) ?><?php endif; ?>
    <ul class="app-items">
    <?php foreach ($certificates as $c):
        $this->insert('partials/item', ['icon' => 'shield', 'tone' => $c['result'] === 'clear' ? 'success' : 'warning', 'title' => 'Certidão nº ' . $c['id'],
            'lines' => ['Referência ' . F::date($c['as_of']) . ($c['result'] === 'debts' ? ' · ' . F::money($c['total_cents']) . ' em atraso' : ''), 'Código ' . $c['verification_code']],
            'badge' => [$c['result_label'], $c['result'] === 'clear' ? 'success' : 'warning'], 'href' => app_url('/cnd/' . $c['id'])]);
    endforeach; ?>
    </ul>
</section>
