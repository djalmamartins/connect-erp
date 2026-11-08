<?php
/** @var list<array{label: string, href: ?string}> $breadcrumbs */
$last = count($breadcrumbs) - 1;
?>
<nav class="erp-breadcrumbs" aria-label="Você está em">
    <ol>
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <li><?php if ($crumb['href'] !== null && $index !== $last): ?><a href="<?= erp_url($crumb['href']) ?>"><?= $this->e($crumb['label']) ?></a><?php else: ?><span<?= $index === $last ? ' aria-current="page"' : '' ?>><?= $this->e($crumb['label']) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
    </ol>
</nav>
