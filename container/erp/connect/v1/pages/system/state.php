<?php
/** Estados de erro (403, 404, 500) dentro da moldura. @var int $stateCode @var string $stateTitle @var string $stateMessage */
$this->layout('default');
?>
<section class="erp-card" style="max-width:640px;margin:48px auto">
    <div class="erp-empty">
        <span class="erp-empty__icon"><?= $icon($stateCode === 403 ? 'lock' : 'alert') ?></span>
        <span class="erp-eyebrow">Erro <?= (int)$stateCode ?></span>
        <h3><?= $this->e($stateTitle) ?></h3>
        <p><?= $this->e($stateMessage) ?></p>
        <?php if ($withNavigation): ?>
            <a class="erp-btn erp-btn--primary" href="<?= url('/erp/dashboard') ?>">Ir para o dashboard</a>
        <?php else: ?>
            <form method="post" action="<?= url('/erp/sair') ?>"><?= csrf_input() ?><button class="erp-btn" type="submit">Sair e entrar com outra conta</button></form>
        <?php endif; ?>
    </div>
</section>
