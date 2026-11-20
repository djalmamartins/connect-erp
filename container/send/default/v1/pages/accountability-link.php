<?php
/**
 * Prestação de contas do condomínio (ADR 0301): o link do livro do mês ou do ano.
 * Variáveis: $first_name, $condominium, $period_label, $annual ('1' no livro anual), $accountability_link.
 */
$isAnnual = ($annual ?? '') === '1';
$this->layout('default', ['subject' => 'Prestação de contas do condomínio', 'preheader' => ($isAnnual ? 'O livro anual' : 'A prestação de contas') . ' ' . ($period_label ?? '') . ' está disponível.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 20px;"><?= $isAnnual ? 'O livro anual da prestação de contas' : 'A prestação de contas' ?> <?= $this->e($period_label ?? '') ?> do <?= $this->e($condominium) ?> já está disponível. Nela estão as contas pagas e recebidas, por categoria e fornecedor, com os comprovantes, as contas e as notas fiscais de cada pagamento.</p>
<?php $this->insert('partials/button', ['label' => 'Ver a prestação de contas', 'href' => $accountability_link]); ?>
<?php $this->insert('partials/link-fallback', ['href' => $accountability_link]); ?>
<?php $this->insert('partials/note', ['text' => 'O link é do seu condomínio. Se a administração corrigir algum valor, o mesmo link passa a mostrar a versão corrigida. Para deixar de receber comunicados por e-mail, fale com a administração.']); ?>
