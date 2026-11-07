<?php
/** Códigos de recuperação do 2FA, mostrados uma vez. @var list<string> $recoveryCodes */
$this->layout('default');
$this->insert('components/layout/page-head', ['pageEyebrow' => 'Segurança', 'pageTitle' => 'Códigos de recuperação', 'pageLead' => 'A verificação em duas etapas foi ativada.']);
?>
<section class="erp-card" style="max-width:640px">
    <?php if ($recoveryCodes === []): ?>
        <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'shield', 'emptyTitle' => 'Nenhum código para mostrar', 'emptyText' => 'Os códigos de recuperação aparecem uma única vez, logo após ativar o 2FA. Para gerar novos, use Segurança e 2FA no Studio.']); ?>
    <?php else: ?>
        <div class="erp-alert erp-alert--warning"><?= $icon('alert') ?><div><strong>Guarde estes códigos agora</strong>Cada um permite entrar uma vez se você perder o aplicativo autenticador. Eles não serão mostrados de novo.</div></div>
        <ul style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin:16px 0;padding:0;list-style:none;font:600 15px/1.6 ui-monospace,Menlo,monospace">
            <?php foreach ($recoveryCodes as $code): ?><li style="padding:8px 12px;border-radius:8px;background:var(--erp-surface-muted)"><?= $this->e($code) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div class="erp-form__actions"><a class="erp-btn erp-btn--primary" href="<?= url('/erp/dashboard') ?>">Continuar para o dashboard</a></div>
</section>
