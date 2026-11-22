<?php
/**
 * CND (ADR 0637): a certidão negativa de débitos da unidade. O morador não emite: pede à administradora (chamado do
 * Help Desk), a equipe emite no ERP e a certidão aparece aqui para baixar em PDF. Com conta vencida em aberto, não há
 * botão de pedido e o servidor recusa: a tela só informa o débito. Qualquer pessoa confere pelo código em
 * /verificar-certidao-debitos.
 *
 * @var array $link
 * @var list<array> $certificates ResidentCertificates::list()
 * @var array $certificateStatus ResidentCertificates::status()
 */
use Source\Controllers\App\Resident\Format as F;

$this->layout('default');
$status = $certificateStatus ?? ['blocked' => false, 'message' => null, 'request' => null];
?>
<header class="app-page-head"><span class="app-eyebrow"><?= $this->e($link['condominium_name']) ?></span><h1>CND</h1><p>Certidão negativa de débitos da unidade <?= $this->e($link['unit_label']) ?>, para venda, aluguel ou financiamento.</p></header>
<section class="app-card">
<?php if ($status['blocked']): ?>
    <h2>Há débito em aberto</h2>
    <p class="app-hint"><?= $this->e((string)$status['message']) ?></p>
    <a class="app-button" href="<?= app_url('/boletos') ?>">Ver boletos</a>
<?php elseif ($status['request'] !== null): ?>
    <h2>Pedido em andamento</h2>
    <p class="app-hint">A administradora está preparando a certidão (protocolo <?= $this->e($status['request']['protocol']) ?>, pedido em <?= $this->e(F::date(substr((string)$status['request']['created_at'], 0, 10))) ?>). Ela aparece aqui quando for emitida.</p>
<?php else: ?>
    <h2>Pedir a certidão</h2>
    <p class="app-hint">A administradora confere a unidade, emite a certidão e ela aparece aqui, com um código para qualquer pessoa conferir a autenticidade. Com conta vencida em aberto, a certidão não é emitida.</p>
    <form method="post" action="<?= app_url('/cnd') ?>" class="app-form">
        <?= csrf_input() ?>
        <button class="app-button" type="submit">Pedir certidão</button>
    </form>
<?php endif; ?>
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
