<?php
/**
 * Cabeçalho de página: contexto, título, descrição e ações. Páginas não o recriam.
 * @var string $pageTitle @var ?string $pageEyebrow @var ?string $pageLead @var ?string $pageActions (HTML montado pela página)
 */
?>
<header class="erp-page-head">
    <div>
        <?php if (!empty($pageEyebrow)): ?><span class="erp-eyebrow"><?= $this->e($pageEyebrow) ?></span><?php endif; ?>
        <h1><?= $this->e($pageTitle) ?></h1>
        <?php if (!empty($pageLead)): ?><p><?= $this->e($pageLead) ?></p><?php endif; ?>
    </div>
    <?php if (!empty($pageActions)): ?><div class="erp-page-head__actions"><?= $pageActions ?></div><?php endif; ?>
</header>
