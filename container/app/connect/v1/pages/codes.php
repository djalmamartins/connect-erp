<?php /** Códigos de recuperação do 2FA recém-configurado (ADR 0302). @var list<string> $codes */ $this->layout('default'); ?>
<header class="app-page-head"><h1>Códigos de recuperação</h1></header>
<div class="app-card">
<?php if ($codes === []): ?>
    <p>Os códigos só aparecem uma vez, logo depois de configurar a verificação em duas etapas.</p>
<?php else: ?>
    <p>Guarde estes códigos num lugar seguro. Cada um vale uma vez, se você perder o celular do autenticador.</p>
    <ul class="app-codes"><?php foreach ($codes as $code): ?><li><code><?= $this->e((string)$code) ?></code></li><?php endforeach; ?></ul>
<?php endif; ?>
    <p><a class="app-button" href="<?= app_url('/') ?>">Continuar</a></p>
</div>
