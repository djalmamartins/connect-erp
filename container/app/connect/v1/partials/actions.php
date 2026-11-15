<?php
/**
 * Botões grandes com ícone, dois por linha (revisão de 15/11): Início, Resolva fácil e Unidade.
 * @var list<array{0:string,1:?string,2:string,3?:string}> $items [rótulo, endereço (null = em breve), ícone, linha de apoio]
 * @var string $label nome do grupo para leitores de tela
 */
?>
<nav class="app-actions" aria-label="<?= $this->e($label ?? 'Serviços') ?>">
    <?php foreach ($items as $item): [$text, $href, $icon] = $item; $hint = $item[3] ?? ''; ?>
        <?php if ($href !== null): ?>
            <a class="app-action" href="<?= $this->e($href) ?>"><span class="app-action__icon" aria-hidden="true"><svg><use href="#i-<?= $this->e($icon) ?>"></use></svg></span><span class="app-action__text"><strong><?= $this->e($text) ?></strong><?php if ($hint !== ''): ?><small><?= $this->e($hint) ?></small><?php endif; ?></span></a>
        <?php else: ?>
            <span class="app-action is-soon" aria-disabled="true"><span class="app-action__icon" aria-hidden="true"><svg><use href="#i-<?= $this->e($icon) ?>"></use></svg></span><span class="app-action__text"><strong><?= $this->e($text) ?></strong><small>Em breve</small></span></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
