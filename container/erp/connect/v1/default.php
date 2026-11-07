<?php
/**
 * Moldura única do ERP Connect v1 (C3: o tema ERP não tem layouts/).
 * Variáveis: $head (SEO), $user (Source\Models\User), $active (item do menu).
 * As páginas chamam $this->layout('default') e preenchem a seção 'content'.
 */
$cssFile = __DIR__ . '/assets/css/erp.css';
$navigation = [
    ['condominiums', 'Condomínios', '/erp/condominios', 'erp.condominiums.view'],
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= $head ?? '' ?>
    <link rel="stylesheet" href="<?= themeErp('/assets/css/erp.css') . '?v=' . (is_file($cssFile) ? filemtime($cssFile) : 0) ?>">
</head>
<body class="erp-body">
<a class="erp-skip-link" href="#erp-main">Ir para o conteúdo</a>
<div class="erp-shell">
    <aside class="erp-sidebar" aria-label="Navegação do ERP">
        <a class="erp-brand" href="<?= url('/erp') ?>"><strong>Connect</strong><small>ERP</small></a>
        <nav>
            <ul>
                <?php foreach ($navigation as [$key, $label, $href, $permission]): ?>
                    <?php if ($user->can($permission)): ?>
                        <li><a href="<?= url($href) ?>"<?= ($active ?? '') === $key ? ' aria-current="page"' : '' ?>><?= $this->e($label) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
    </aside>
    <div class="erp-content">
        <header class="erp-navbar">
            <span class="erp-user"><?= $this->e(trim($user->first_name . ' ' . $user->last_name)) ?></span>
            <a class="erp-logout" href="<?= url('/studio/sair') ?>">Sair</a>
        </header>
        <main id="erp-main" class="erp-main" tabindex="-1">
            <?= $this->section('content') ?>
        </main>
    </div>
</div>
</body>
</html>
