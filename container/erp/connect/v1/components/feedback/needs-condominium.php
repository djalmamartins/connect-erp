<?php
/**
 * Filtro de unidade sem condomínio escolhido (revisão de 15/11, ADR da etapa 1): a lista de unidades
 * só aparece com um condomínio na barra superior e mostra só as unidades dele, em todas as telas.
 * @var ?string $needsCondoText  complemento opcional (o que a tela faz depois de escolher)
 */
?>
<div class="erp-alert" role="note" data-needs-condominium><?= $icon('info') ?><div><strong>Escolha um condomínio na barra superior.</strong> A unidade é escolhida entre as do condomínio selecionado.<?= isset($needsCondoText) && $needsCondoText !== '' ? ' ' . $this->e($needsCondoText) : '' ?></div></div>
