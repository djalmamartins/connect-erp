<?php
/** Recibo de pagamento da unidade (ADR 0302, ajustes de 14/11). @var array $receipt ResidentPortal::receipt() */
use Source\Controllers\App\Resident\Format as F;

$this->layout('print');
$r = $receipt;
$doc = preg_replace('/\D/', '', (string)$r['condominium_document']);
$doc = strlen($doc) === 14 ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $doc) : (string)$r['condominium_document'];
?>
<article class="doc">
    <div class="doc-head">
        <img src="<?= $this->e(site_logo_url()) ?>" alt="Connect Condomínios">
        <small>Recibo nº <?= $this->e($r['number']) ?><br>Emitido em <?= F::dateTime($r['issued_at']) ?></small>
    </div>
    <h1>Recibo de pagamento</h1>
    <p class="doc-muted"><?= $this->e($r['condominium_name']) ?><?= $r['condominium_document'] ? ' · CNPJ ' . $this->e($doc) : '' ?></p>
    <div class="doc-grid">
        <div><span>Unidade</span><?= $this->e($r['unit_label']) ?></div>
        <div><span>Morador</span><?= $this->e($r['payer']) ?></div>
        <div><span>Referente a</span><?= $this->e($r['description']) ?></div>
        <div><span>Competência</span><?= F::competence($r['competence']) ?></div>
        <div><span>Data do pagamento</span><?= F::date($r['paid_on']) ?></div>
    </div>
    <div class="doc-total"><span>Valor pago</span><strong><?= F::money($r['amount_cents']) ?></strong></div>
    <p>Recebemos da unidade <?= $this->e($r['unit_label']) ?> o valor acima, referente a “<?= $this->e($r['description']) ?>”, dando plena quitação desta parcela.</p>
    <p class="doc-muted">Recibo gerado pelo aplicativo do morador com base na baixa registrada pela administração. Em caso de dúvida, informe o número do recibo.</p>
    <div class="doc-sign"><hr>Connect Condomínios · administradora</div>
</article>
