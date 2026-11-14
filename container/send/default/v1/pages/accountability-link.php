<?php
/**
 * Prestação de contas do condomínio (ADR 0301): o link do livro do mês ou do ano.
 * Variáveis: $first_name, $condominium, $period_label, $annual ('1' no livro anual), $accountability_link.
 */
$isAnnual = ($annual ?? '') === '1';
$this->layout('default', ['subject' => 'Prestação de contas do condomínio', 'preheader' => ($isAnnual ? 'O livro anual' : 'A prestação de contas') . ' ' . ($period_label ?? '') . ' está disponível.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;"><?= $isAnnual ? 'O livro anual da prestação de contas' : 'A prestação de contas' ?> <?= $this->e($period_label ?? '') ?> do <?= $this->e($condominium) ?> já está disponível. Nela estão as contas pagas e recebidas, por categoria e fornecedor, com os comprovantes, as contas e as notas fiscais de cada pagamento.</p>
<p style="margin:0 0 24px;">
    <a href="<?= $this->e($accountability_link) ?>" style="display:inline-block;background:#0d2e27;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:bold;border-bottom:3px solid #c5a131;">Ver a prestação de contas</a>
</p>
<p style="margin:0 0 8px;font-size:13px;color:#5b6878;">Se o botão não funcionar, copie este endereço no navegador:</p>
<p style="margin:0 0 16px;font-size:13px;word-break:break-all;"><?= $this->e($accountability_link) ?></p>
<p style="margin:0;font-size:13px;color:#5b6878;">O link é do seu condomínio. Se a administração corrigir algum valor, o mesmo link passa a mostrar a versão corrigida. Para deixar de receber comunicados por e-mail, fale com a administração.</p>
